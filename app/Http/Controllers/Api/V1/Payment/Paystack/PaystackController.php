<?php

namespace App\Http\Controllers\Api\V1\Payment\Paystack;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use App\Models\Request\Request as RequestModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Jobs\Notifications\SendPushNotification;
use App\Models\User;
use App\Models\Payment\UserWallet;
use App\Models\Payment\DriverWallet;
use App\Models\Payment\OwnerWallet;
use App\Models\Payment\UserWalletHistory;
use App\Models\Payment\DriverWalletHistory;
use App\Models\Payment\OwnerWalletHistory;
use App\Base\Constants\Setting\Settings;
use App\Base\Constants\Masters\WalletRemarks;
use Kreait\Firebase\Contract\Database;

/**
 * @group Paystack Payment Gateway
 *
 * Payment-Related Apis
 */
class PaystackController extends ApiController
{
    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Initialize Payment.
     */
    public function initialize(Request $request)
    {
        $paystack_initialize_url = 'https://api.paystack.co/transaction/initialize';

        if (get_settings(Settings::PAYSTACK_ENVIRONMENT) == 'test') {
            $secret_key = get_settings(Settings::PAYSTACK_TEST_SECRET_KEY);
        } else {
            $secret_key = get_settings(Settings::PAYSTACK_PRODUCTION_SECRET_KEY);
        }

        $headers = [
            'Authorization:Bearer '.$secret_key,
            'Content-Type:application/json',
        ];

        $customer_email = auth()->user()->email;
        $amount = $request->amount;
        $request_for = 'add-money-to-wallet';
        $current_timestamp = Carbon::now()->timestamp;
        $reference = auth()->user()->id;

        if ($request->has('payment_for')) {
            $request_for = $request->payment_for;
        }

        $query = [
            'email' => $customer_email,
            'amount' => $request->amount,
            'reference' => $current_timestamp.'-----'.$reference.'-----'.$request_for,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $paystack_initialize_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);

        if ($result) {
            $result = json_decode($result);

            return response()->json($result);
        }

        return $this->respondFailed();
    }

    /**
     * Handle card tokenization.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tokenizeCard(Request $request)
    {
        $request->validate([
            'card_number' => 'required|string',
            'expiry_month' => 'required|string',
            'expiry_year' => 'required|string',
            'cvv' => 'required|string',
            'email' => 'required|string|email',
        ]);

        $paystack_url = 'https://api.paystack.co/charge/tokenize';
        $secret_key = config('services.paystack.secret');
        $headers = [
            'Authorization: Bearer '.$secret_key,
            'Content-Type: application/json',
        ];

        $data = [
            'email' => $request->email,
            'card' => [
                'number' => $request->card_number,
                'expiry_month' => $request->expiry_month,
                'expiry_year' => $request->expiry_year,
                'cvv' => $request->cvv,
            ],
        ];

        $response = Http::withHeaders($headers)->post($paystack_url, $data);

        if ($response->successful()) {
            $token = $response->json()['data']['authorization_code'];

            // Save token to database or any other action

            return response()->json(['token' => $token], 200);
        }

        Log::error('Paystack Tokenization Failed: '.$response->body());

        return response()->json(['error' => 'Tokenization failed'], 500);
    }

    // Other methods like webHook and makePaymentForRide remain unchanged
}
