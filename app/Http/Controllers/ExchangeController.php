<?php

namespace App\Http\Controllers;

use App\Jobs\GetCurrencyRatesJob;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use App\Http\Services\XecdRatesClient;
use App\ExConvertRates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class ExchangeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $xecdRatesClient;
    public function __construct()
    {
        $apiAccountId = env('XE_API_ACCOUNT_ID');
        $apiKey = env('XE_API_KEY');
        $this->xecdRatesClient = XecdRatesClient::create($apiAccountId, $apiKey);
    }

    /**
     * convert currency with rate
     *
     * @param Request $request
     * @return void
     * @throws GuzzleException
     */
    public function convertFrom(Request $request)
    {
        if ($request->has('from')){
            $from = strtoupper($request->input('from'));
        }
        if ($request->has('to')){
            $to = strtoupper($request->input('to'));
        }
        if ($request->has('amount')){
            $amount = $request->input('amount');
        }
        if (!isset($from) || !isset($to) | !isset($amount)) {
            return response()->json(['error'=>'Invalid Data'], 400);
        }

        $lists = $this->xecdRatesClient->getCurrencyList();
        if (!in_array($from, $lists) || !in_array($to, $lists)) {
            return response()->json(['error'=>'Invalid Data'], 400);
        }
        list($mid, $rate) = $this->convertCurrency($from, $to, $amount);
        return response()->json(['from'=>$from, 'to'=>$to, 'amount'=>$amount, 'convert_rate'=>$mid]);
    }

    // set default currency from is USD and amount = 1
    function getRate (Request $request) {
        $from = 'USD';
        $amount = 1;
        list($mid, $rate) = $this->convertCurrency($from, '', 1);
        $to = [];
        if (count($rate) == 0) {
            return response()->json(['error'=>'System error'], 500);
        }
        foreach ($rate as $key=> $value) {
            $to[] = ['currency'=>$key, 'mid'=>$value[0]];
        }
        return response()->json(['from'=>$from,'amount'=>$amount, 'to'=>$to]);
    }

    function convertCurrency($from, $to, $amount) {
        $rate = [];
        if (Redis::exists('USD')) {
            $rate = unserialize(Redis::get('USD'));
        } else {
            $exConvertRates = ExConvertRates::where('effective_date', Carbon::today())->get();
            if (count($exConvertRates) === 0) {
                GetCurrencyRatesJob::dispatch('USD');

                $conversions = $this->xecdRatesClient->convertFrom($from, $to, $amount, 0,false, true);
                $res = json_decode($conversions->getBody()->getContents());
                return [$res->to[0]->mid, []];
            } else {
                foreach ($exConvertRates as $exConvertRate) {
                    $rate[$exConvertRate->to_currency] = [$exConvertRate->rate, $exConvertRate->inverse_rate];
                }
                Redis::set('USD', serialize($rate));
            }
        }
        list($usdFromRate, $fromInverse) = $rate[$from];
        if ($to) {
            list($usdToRate, $toInverse) = $rate[$to];
            return [$fromInverse*$usdToRate*$amount, $rate];
        }
        return [0, $rate];
    }
}
