<?php

namespace App\Http\Services;

use Twilio\Exceptions\ConfigurationException;
use Twilio\Rest\Client;

class TwilioServices
{
    protected Client $client;

    /**
     * @throws ConfigurationException
     */
    public function __construct()
    {
        $this->client = new Client(config('services.twilio.sid'), config('services.twilio.token'));
    }

    public function sendSMS($to, $message): void
    {
        $this->client->messages->create(
            // Where to send a text message (your cell phone?)
            $to,
            [
                'from' => 'Your_Twilio_Number',
                'body' => $message,
            ]
        );
    }
}
