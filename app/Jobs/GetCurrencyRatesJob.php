<?php

namespace App\Jobs;

use App\ExConvertRates;
use App\Http\Services\XecdRatesClient;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Http\Client\Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class GetCurrencyRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    private $from;
    public function __construct($from)
    {
        $this->from = $from;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws GuzzleException
     */
    public function handle()
    {
        Log::info('download ex rate of '. $this->from.Carbon::now());
        $apiAccountId = env('XE_API_ACCOUNT_ID');
        $apiKey = env('XE_API_KEY');
        $xecdRatesClient = XecdRatesClient::create($apiAccountId, $apiKey);
        try
        {
            $conversions = $xecdRatesClient->convertFrom($this->from, null, 1, 0,false, true);
            $res = json_decode($conversions->getBody()->getContents());
            $data = [];
            $rate = [];
            foreach ($res->to as $datum) {
                $rate[$datum->quotecurrency] = [$datum->mid, $datum->inverse];
                $data[] = ['from_currency' => $res->from,
                    'to_currency' => $datum->quotecurrency,
                    'rate' => $datum->mid,
                    'inverse_rate' => $datum->inverse,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                    'effective_date' => Carbon::parse($res->timestamp)];
            }
            ExConvertRates::insert($data);
            Redis::set($res->from, serialize($rate));
        } catch (\Exception $e) {
            Log::error('error when get currency rate, currency: '.$this->from.', msg: '.$e->getMessage());
        }


    }
}
