<?php

namespace App\Http\Controllers\Api\V1\Request;

use App\Jobs\NotifyViaMqtt;
use App\Jobs\NotifyViaSocket;
use App\Models\Request\RequestMeta;
use App\Base\Constants\Masters\UserType;
use App\Base\Constants\Masters\PushEnums;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Request\CancelTripRequest;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Transformers\Requests\TripRequestTransformer;
use App\Base\Constants\Masters\WalletRemarks;
use App\Base\Constants\Masters\zoneRideType;
use App\Base\Constants\Masters\PaymentType;
use App\Models\Admin\CancellationReason;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Factory;
use App\Jobs\Notifications\SendPushNotification;
use Illuminate\Http\Request;
use App\Models\Request\Request as RequestRequest;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Web\StripeController;

class UserCancelRequestController extends BaseController
{
    protected $stripeController;

    public function __construct(Database $database, StripeController $stripeController)
    {
        $this->database = $database;
        $this->stripeController = $stripeController;
    }

    public function cancelRequest(CancelTripRequest $request)
    {
        $user = auth()->user();
        $request_detail = $user->requestDetail()->where('id', $request->request_id)->first();
    
        if (!$request_detail) {
            $this->throwAuthorizationException();
        }
    
        $request_detail->update([
            'is_cancelled' => true,
            'reason' => $request->reason,
            'custom_reason' => $request->custom_reason,
            'cancel_method' => UserType::USER,
            'cancelled_at' => now(),
        ]);

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
                if ($reason->payment_type == 'free') {
                    $charge_applicable = false;
                } else {
                    $charge_applicable = true;
                }
            } else {
                $charge_applicable = false;
            }
        }
    
        $ride_type = $request_detail->is_later ? zoneRideType::RIDELATER : zoneRideType::RIDENOW;
    
        if ($charge_applicable) {
            $zone_type_price = $request_detail->zoneType->zoneTypePrice()->where('price_type', $ride_type)->first();
            $cancellation_fee = $zone_type_price->cancellation_fee;
    
            if ($request_detail->payment_opt == PaymentType::WALLET) {
                $requested_user = $request_detail->userDetail;
                $user_wallet = $requested_user->userWallet;
                $user_wallet->amount_spent += $cancellation_fee;
                $user_wallet->amount_balance -= $cancellation_fee;
                $user_wallet->save();
    
                $requested_user->userWalletHistory()->create([
                    'amount' => $cancellation_fee,
                    'transaction_id' => $request_detail->id,
                    'remarks' => WalletRemarks::CANCELLATION_FEE,
                    'request_id' => $request_detail->id,
                    'is_credit' => false,
                ]);
                $request_detail->requestCancellationFee()->create([
                    'user_id' => $request_detail->user_id,
                    'is_paid' => true,
                    'cancellation_fee' => $cancellation_fee,
                    'paid_request_id' => $request_detail->id,
                ]);
            } else {
                $request_detail->requestCancellationFee()->create([
                    'user_id' => $request_detail->user_id,
                    'is_paid' => false,
                    'cancellation_fee' => $cancellation_fee,
                ]);
            }
        }
    
        $driver = null;
        $request_driver = $request_detail->driverDetail;
        if ($request_driver) {
            $driver = $request_driver;
        } else {
            $request_meta_driver = $request_detail->requestMeta()->where('active', true)->first();
            if ($request_meta_driver) {
                $driver = $request_meta_driver->driver;
            }
        }
    
        // Remove the request meta from Firebase
        $this->database->getReference('request-meta/' . $request_detail->id)->remove();
    
        // Notify the driver about the cancellation
        if ($driver) {
            $driver->available = true;
            $driver->save();
    
            $notifiable_driver = $driver->user;
            $request_result = fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');
            $push_request_detail = $request_result->toJson();
            $title = trans('push_notifications.trip_cancelled_by_user_title', [], $notifiable_driver->lang);
            $body = trans('push_notifications.trip_cancelled_by_user_body', [], $notifiable_driver->lang);
    
            dispatch(new SendPushNotification($notifiable_driver, $title, $body));
    
            $socket_data = new \stdClass();
            $socket_data->success = true;
            $socket_data->success_message = PushEnums::REQUEST_CANCELLED_BY_USER;
            $socket_data->result = $request_result;
    
            dispatch(new NotifyViaSocket('trip_canceled', $socket_data, $driver->id));
        }
    
        // Clean up request metadata and reassign drivers
        $request_detail->requestMeta()->delete();
        Artisan::call('assign_drivers:for_regular_rides');
    
        return $this->respondSuccess();
    }

    public function paymentMethod(Request $request)
    {
        $user = auth()->user();
        $request_detail = $user->requestDetail()->where('id', $request->request_id)->first();

        if (!$request_detail) {
            $this->throwAuthorizationException();
        }

        $request_detail->update([
            'payment_opt' => $request->payment_opt,
        ]);

        if ($request_detail->payment_opt == PaymentType::CASH) {
            $request_detail->update([
                'is_paid' => 0,
            ]);
        }

        return $this->respondSuccess();
    }

    public function userPaymentConfirm(Request $request)
    {
        $user = auth()->user();
        $request_detail = $user->requestDetail()->where('id', $request->request_id)->first();

        if (!$request_detail) {
            $this->throwAuthorizationException();
        }

        $request_detail->update([
            'is_paid' => 1,
        ]);

        return $this->respondSuccess();
    }
}
