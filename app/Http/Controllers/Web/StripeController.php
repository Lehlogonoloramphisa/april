<?php

namespace App\Http\Controllers\Web;

use Stripe\Webhook;
use Illuminate\Http\Request as ValidatorRequest;
use App\Http\Controllers\Controller;
use App\Base\Constants\Masters\PushEnums;
use App\Models\Payment\OwnerWallet;
use App\Models\Payment\OwnerWalletHistory;
use App\Transformers\Payment\OwnerWalletTransformer;
use App\Jobs\Notifications\SendPushNotification;
use App\Models\Payment\UserWalletHistory;
use App\Models\Payment\DriverWalletHistory;
use App\Transformers\Payment\WalletTransformer;
use App\Transformers\Payment\DriverWalletTransformer;
use App\Http\Requests\Payment\AddMoneyToWalletRequest;
use App\Transformers\Payment\UserWalletHistoryTransformer;
use App\Transformers\Payment\DriverWalletHistoryTransformer;
use App\Models\Payment\UserWallet;
use App\Models\Payment\DriverWallet;
use App\Base\Constants\Masters\WalletRemarks;
use App\Jobs\Notifications\AndroidPushNotification;
use App\Base\Constants\Auth\Role;
use Carbon\Carbon;
use App\Models\Request\Request as RequestModel;
use App\Models\User;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Database;
use App\Base\Constants\Setting\Settings;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Log;

class StripeController extends Controller
{
    private $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function stripe(ValidatorRequest $request)
    {
        $amount = ($request->input('amount') * 100);
        $payment_for = $request->input('payment_for');
        $user_id = $request->input('user_id');
        $request_id = $request->input('request_id');

        $user = User::find($user_id);
        $currency = $user->countryDetail->currency_code ?? "INR";

        return view('stripe.stripe', compact('amount', 'payment_for', 'currency', 'user_id', 'request_id'));
    }

    public function stripeCheckout(ValidatorRequest $request)
    {
        \Stripe\Stripe::setApiKey(config('stripe.sk'));

        $productname = $request->get('productname');
        $payment_for = $request->get('payment_for');
        $currency = $request->get('currency');
        $amount = $request->get('amount');
        $user_id = $request->get('user_id');
        $request_id = $request->get('request_id');

        $total = $amount * 100; // Calculate total in smallest currency unit

        // Create a Checkout Session
        $session = \Stripe\Checkout\Session::create([
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $productname,
                        ],
                        'unit_amount' => $total,
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'payment_intent_data' => [
                'capture_method' => 'manual',
                'metadata' => [
                    'user_id' => $user_id,
                ],
            ],
            'success_url' => route('checkout.success') . '?productname=' . urlencode($productname) . '&payment_for=' . urlencode($payment_for) . '&currency=' . urlencode($currency) . '&amount=' . urlencode($amount) . '&user_id=' . urlencode($user_id) . '&request_id=' . urlencode($request_id),
            'cancel_url' => route('checkout.failure'),
            'client_reference_id' => $user_id, // Set the user_id as the client_reference_id
        ]);

        session(['checkout_session_id' => $session->id]);

        return redirect()->away($session->url);
    }

    public function stripeCheckoutSuccess(ValidatorRequest $request)
    {
        $web_booking_value = 0;

        $payment_for = $request->get('payment_for');
        $currency = $request->get('currency');
        $amount = $request->get('amount');
        $user_id = $request->get('user_id');
        $request_id = $request->get('request_id');
        $user = User::find($user_id);

        if ($payment_for == "wallet") {
            $request_id = null;

            if ($user->hasRole('user')) {
                $wallet_model = new UserWallet();
                $wallet_add_history_model = new UserWalletHistory();
                $user_id = $user->id;
            } elseif ($user->hasRole('driver')) {
                $wallet_model = new DriverWallet();
                $wallet_add_history_model = new DriverWalletHistory();
                $user_id = $user->driver->id;
            } else {
                $wallet_model = new OwnerWallet();
                $wallet_add_history_model = new OwnerWalletHistory();
                $user_id = $user->owner->id;
            }

            $user_wallet = $wallet_model::firstOrCreate(['user_id' => $user_id]);
            $user_wallet->amount_added += $amount;
            $user_wallet->amount_balance += $amount;
            $user_wallet->save();
            $user_wallet->fresh();

            // Get the payment intent ID from the session (set in the webhook handler)
            $payment_intent_id = session('stripe_payment_intent_id');

            $wallet_add_history_model::create([
                'user_id' => $user_id,
                'amount' => $amount,
                'transaction_id' => $payment_intent_id, // Use the payment intent ID as the transaction ID
                'remarks' => WalletRemarks::MONEY_DEPOSITED_TO_E_WALLET,
                'is_credit' => true,
            ]);

            $title = trans('push_notifications.amount_credited_to_your_wallet_title');
            $body = trans('push_notifications.amount_credited_to_your_wallet_body');
            dispatch(new SendPushNotification($user, $title, $body));

            if ($user->hasRole(Role::USER)) {
                $result = fractal($user_wallet, new WalletTransformer);
            } elseif ($user->hasRole(Role::DRIVER)) {
                $result = fractal($user_wallet, new DriverWalletTransformer);
            } else {
                $result = fractal($user_wallet, new OwnerWalletTransformer);
            }
        } else {
            $request_id = $request->get('request_id');
            $request_detail = RequestModel::where('id', $request_id)->first();
            $web_booking_value = $request_detail->web_booking;

            $request_detail->update(['is_paid' => true]);
            $this->database->getReference('requests/' . $request_detail->id)->update(['is_paid' => 1]);
        }

        return view('success', compact('web_booking_value', 'request_id'));
    }

    public function stripeCheckoutError(ValidatorRequest $request)
    {
        return view('failure');
    }

    public function handleStripeWebhook(ValidatorRequest $request)
    {
        // Verify the webhook signature
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('stripe.webhook_secret');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Stripe webhook payload error: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid Stripe webhook payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Stripe webhook signature verification error: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid Stripe webhook signature'], 400);
        }

        // Handle the event based on type
        switch ($event->type) {
            case 'payment_intent.created':
                $session = $event->data->object;
                $payment_intent_id = $session->id;

                // Retrieve the user ID from the session metadata
                $user_id = $session->metadata->user_id ?? null; // Ensure this field is set when creating the session

                
                    // Store the payment intent ID in Firebase
                    $this->database->getReference('requests/user_id/' . $user_id)
                        ->update(['payment_intent_id' => $payment_intent_id]);
                

                break;

            // Add more cases to handle other webhook events as needed
            default:
                Log::info('Unhandled event type: ' . $event->type);
                break;
        }

        return response()->json(['success' => true]);
    }
}
