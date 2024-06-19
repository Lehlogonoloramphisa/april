<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Jobs\NotifyViaMqtt;
use App\Jobs\NotifyViaSocket;
use App\Base\Constants\Masters\UserType;
use App\Base\Constants\Masters\PushEnums;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\CancelTripRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Models\Admin\CancellationReason;
use App\Base\Constants\Masters\zoneRideType;
use App\Base\Constants\Masters\WalletRemarks;
use App\Models\Request\DriverRejectedRequest;
use App\Jobs\Notifications\SendPushNotification;
use Illuminate\Support\Facades\Artisan;
use Kreait\Firebase\Contract\Database;
use App\Base\Constants\Masters\PaymentType;

class DriverCancelRequestController extends BaseController
{
    protected $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
    * Driver Cancel Trip Request
    * @bodyParam request_id uuid required id of request
    * @bodyParam reason string optional reason provided by driver
    * @bodyParam custom_reason string optional custom reason provided by driver
    * @response {
    "success": true,
    "message": "driver_cancelled_trip"}
    */
    public function cancelRequest(CancelTripRequest $request)
    {
        $driver = auth()->user()->driver;
        $driver->available = true;
        $driver->save();

        $request_detail = $driver->requestDetail()->where('id', $request->request_id)->first();
        if (!$request_detail) {
            $this->throwAuthorizationException();
        }

        $request_detail->update([
            'is_cancelled' => true,
            'reason' => $request->reason,
            'custom_reason' => $request->custom_reason,
            'cancel_method' => UserType::DRIVER,
            'cancelled_at' => now(),
        ]);

        DriverRejectedRequest::create([
            'request_id' => $request_detail->id,
            'is_after_accept' => true,
            'driver_id' => $driver->id,
            'reason' => $request->reason,
            'custom_reason' => $request->custom_reason
        ]);

        // Get the user detail
        $user = $request_detail->userDetail;

        // Retrieve the payment_intent_id from Firebase Realtime Database using user_id
        if ($request_detail->payment_opt == PaymentType::CARD) {
            $payment_intent_id = $this->database->getReference('requests/user_id/' . $user->id . '/payment_intent_id')->getValue();

            if ($payment_intent_id) {
                // Cancel the Stripe payment intent
                $stripe = new \Stripe\StripeClient(config('stripe.sk'));
                $stripe->paymentIntents->cancel($payment_intent_id, []);
            }
        }

        $charge_applicable = false;
        if ($request->custom_reason) {
            $charge_applicable = true;
        }
        if ($request->reason) {
            $reason = CancellationReason::find($request->reason);
            if ($reason) {
                $charge_applicable = $reason->payment_type != 'free';
            }
        }

        $ride_type = $request_detail->is_later ? zoneRideType::RIDELATER : zoneRideType::RIDENOW;
        if ($charge_applicable) {
            $zone_type_price = $request_detail->zoneType->zoneTypePrice()->where('price_type', $ride_type)->first();
            $cancellation_fee = $zone_type_price->cancellation_fee;

            if ($request_detail->driverDetail->owner()->exists()) {
                $owner_wallet = $request_detail->driverDetail->owner->ownerWalletDetail;
                $owner_wallet->amount_spent += $cancellation_fee;
                $owner_wallet->amount_balance -= $cancellation_fee;
                $owner_wallet->save();

                $owner_wallet_history = $request_detail->driverDetail->owner->ownerWalletHistoryDetail()->create([
                    'amount' => $cancellation_fee,
                    'transaction_id' => $request_detail->id,
                    'remarks' => WalletRemarks::CANCELLATION_FEE,
                    'request_id' => $request_detail->id,
                    'is_credit' => false
                ]);
            } else {
                $driver_wallet = $request_detail->driverDetail->driverWallet;
                $driver_wallet->amount_spent += $cancellation_fee;
                $driver_wallet->amount_balance -= $cancellation_fee;
                $driver_wallet->save();

                $request_detail->driverDetail->driverWalletHistory()->create([
                    'amount' => $cancellation_fee,
                    'transaction_id' => $request_detail->id,
                    'remarks' => WalletRemarks::CANCELLATION_FEE,
                    'request_id' => $request_detail->id,
                    'is_credit' => false
                ]);
            }

            $request_detail->requestCancellationFee()->create([
                'driver_id' => $request_detail->driver_id,
                'is_paid' => true,
                'cancellation_fee' => $cancellation_fee,
                'paid_request_id' => $request_detail->id
            ]);
        }

        if ($user) {
            $request_result = fractal($request_detail, new TripRequestTransformer)->parseIncludes('driverDetail');
            $push_request_detail = $request_result->toJson();
            $title = trans('push_notifications.trip_cancelled_by_driver_title', [], $user->lang);
            $body = trans('push_notifications.trip_cancelled_by_driver_body', [], $user->lang);
            $push_data = ['success' => true, 'success_message' => PushEnums::REQUEST_CANCELLED_BY_DRIVER, 'result' => (string)$push_request_detail];

            $socket_data = new \stdClass();
            $socket_data->success = true;
            $socket_data->success_message = PushEnums::REQUEST_CANCELLED_BY_DRIVER;
            $socket_data->result = $request_result;

            dispatch(new SendPushNotification($user, $title, $body));
            dispatch(new NotifyViaSocket('trip_canceled', $socket_data, $user->id));
        }

        $this->database->getReference('request-meta/' . $request_detail->id)->remove();

        $request_detail->requestMeta()->delete();
        Artisan::call('assign_drivers:for_regular_rides');

        return $this->respondSuccess(null, 'driver_cancelled_trip');
    }
}
