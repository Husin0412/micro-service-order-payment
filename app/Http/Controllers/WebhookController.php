<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function midtransHandler(Request $request)
    {
        // {
        //     "transaction_time": "2020-01-09 18:27:19",
        //     "transaction_status": "capture",
        //     "transaction_id": "57d5293c-e65f-4a29-95e4-5959c3fa335b",
        //     "status_message": "midtrans payment notification",
        //     "status_code": "200",
        //     "signature_key": "46a891df876c8ad091489acf2fb8b22e06ab52ee57b223d71a96b1393d191fd741f317184deee62f0408980a6e8ea43eefc27c07e9db6e7c3e8c80a1d1b8b496",
        //     "payment_type": "credit_card",
        //     "order_id": "14-acsfs",
        //     "merchant_id": "M004123",
        //     "masked_card": "481111-1114",
        //     "gross_amount": "10000.00",
        //     "fraud_status": "accept",
        //     "eci": "05",
        //     "currency": "IDR",
        //     "channel_response_message": "Approved",
        //     "channel_response_code": "00",
        //     "card_type": "credit",
        //     "bank": "bni",
        //     "approval_code": "1578569243927"
        //   }
        
        $data = $request->all();

        $signatureKey = $data['signature_key'];
        $orderId = $data['order_id'];
        $statusCode = $data['status_code'];
        $grossAmount = $data['gross_amount'];
        $serverKey = env('MIDTRANS_SERVER_KEY');

        $mySignatureKey = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        // return $mySignatureKey;
        $transactionStatus = $data['transaction_status'];
        $type = $data['payment_type'];
        $fraudStatus = $data['fraud_status'];

        if ($signatureKey !== $mySignatureKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'invalid signature',
            ], 400);
        }

        $realOrderId = \explode('-', $orderId);
        // return $realOrderId[0];
        $order = Order::find($realOrderId[0]);

        if (!$order) {
            return \response()->json([
                'status' => 'error',
                'message' => 'order id not found',
            ], 404);
        }

        if ($order->status === 'success') {
            return \response()->json([
                'status' => 'error',
                'message' => 'operation not permitted',
            ], 405);
        }

        if ($transactionStatus == 'capture') {
            if ($fraudStatus == 'challenge') {
                $order->status = 'challenge';
            } else if ($fraudStatus == 'accept') {
                $order->status = 'success';
            }
        } else if ($transactionStatus == 'settlement') {
            $order->status = 'success';
        } else if ($transactionStatus == 'cancel' ||
            $transactionStatus == 'deny' ||
            $transactionStatus == 'expire') {
            $order->status = 'failure';
        } else if ($transactionStatus == 'pending') {
            $order->status = 'pending';
        }

        $logsData = [
            'status' => $transactionStatus,
            'raw_response' => \json_encode($data),
            'order_id' => $realOrderId[0],
            'payment_type' => $type,
        ];

        $paymentLog = PaymentLog::create($logsData);
        $order->save();

        if ($order->status === 'success') {
            // memberikan akses premium -> service courses
            createPremiumAccess([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id,
            ]);
        }
        // return $paymentLog;
        return \response()->json('Ok');

    }
}