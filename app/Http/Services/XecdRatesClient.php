<?php

namespace App\Http\Services;

use App\Consts;
use App\Jobs\GetCurrencyRatesJob;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Redis;

class XecdRatesClient
{

    /**
     * @var Client
     */
    protected $client = null;
    /**
     * @var Request
     */
    protected $accountInfoRequest;

    /**
     * @var Request
     */
    protected $currenciesRequest;

    /**
     * @var Request
     */
    protected $convertFromRequest;

    /**
     * @var Request
     */
    protected $convertToRequest;

    /**
     * @var Request
     */
    protected $historicRateRequest;

    /**
     * @var Request
     */
    protected $historicRatePeriodRequest;

    /**
     * @var Request
     */
    protected $monthlyAverageRequest;

    private static $instance = null;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;

        $this->accountInfoRequest = new Request('GET', 'account_info');
        $this->currenciesRequest = new Request('GET', 'currencies');
        $this->convertFromRequest = new Request('GET', 'convert_from');
        $this->convertToRequest = new Request('GET', 'convert_to');
        $this->historicRateRequest = new Request('GET', 'historic_rate');
        $this->historicRatePeriodRequest = new Request('GET', 'historic_rate/period');
        $this->monthlyAverageRequest = new Request('GET', 'monthly_average');
    }

    /**
     * Factory method.
     *
     * @param string $apiAccountId Your account id
     * @param string $apiKey       Your API key
     * @param array  $options      Guzzle request options
     *
     * @return XecdRatesClient
     */
    public static function create($apiAccountId, $apiKey, array $options = [])
    {
        $options['handler'] = isset($options['handler']) ? $options['handler'] : HandlerStack::create();
        $options['handler']->unshift(static::mapRequest());
        if (self::$instance == null)
        {
            $client = new Client(array_merge([
                RequestOptions::TIMEOUT => 15,
                RequestOptions::CONNECT_TIMEOUT => 15,
                'base_uri' => 'https://xecdapi.xe.com',
                RequestOptions::AUTH => [
                    $apiAccountId,
                    $apiKey,
                ],
            ], $options));
            self::$instance = new static($client);
        }

        return self::$instance;
    }

    /**
     * Request account info associated with your api key and secret.
     *
     * @param array $options Guzzle request options
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function accountInfo(array $options = [])
    {
        return $this->send($this->accountInfoRequest, $options);
    }

    /**
     * Request currency information.
     *
     * @param string $language ISO 639-1 language code specifying the language to request currency information in
     * @param null $iso
     * @param bool $obsolete true to request obsolete currencies, false otherwise
     * @param array $options Guzzle request options
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function currencies($language = 'en', $iso = null, $obsolete = false, array $options = [])
    {
        return $this->send($this->currenciesRequest, array_merge($options, [
            RequestOptions::QUERY => [
                'language' => $language,
                'iso' => $iso ?: '*',
                'obsolete' => $obsolete ? 'true' : 'false',
            ],
        ]));
    }

    /**
     * Convert from a single currency to multiple currencies.
     *
     * @param string $from
     * @param null $to
     * @param int $amount Amount to convert
     * @param int $decimalPlaces
     * @param bool $obsolete true to request rates for obsolete currencies, false otherwise
     * @param bool $inverse true to request inverse rates as well, false otherwise
     * @param array $options Guzzle request options
     *
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function convertFrom($from,  $to = null, $amount = 1, $decimalPlaces = 0, $obsolete = false, $inverse = false, array $options = [])
    {
        $query = [
            'from' => $from ?: ('USD'),
            'to' => $to ?: '*',
            'amount' => $amount,
            'obsolete' => $obsolete ? 'true' : 'false',
            'inverse' => $inverse ? 'true' : 'false',
        ];

        if ($decimalPlaces) {
            $query['decimal_places'] = $decimalPlaces;
        }
        return $this->send($this->convertFromRequest, array_merge($options, [
            RequestOptions::QUERY => $query,
        ]));
    }

    /**
     * Convert to a single currency from multiple currencies.
     *
     * @param null $to
     * @param null $from
     * @param int $amount Amount to convert
     * @param bool $obsolete true to request rates for obsolete currencies, false otherwise
     * @param bool $inverse true to request inverse rates as well, false otherwise
     * @param array $options Guzzle request options
     *
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function convertTo($to = null, $from = null, $amount = 1, $obsolete = false, $inverse = false, array $options = [])
    {
        return $this->send($this->convertToRequest, array_merge($options, [
            RequestOptions::QUERY => [
                'to' => $to ?: ('USD'),
                'from' => $from ?: '*',
                'amount' => $amount,
                'obsolete' => $obsolete ? 'true' : 'false',
                'inverse' => $inverse ? 'true' : 'false',
            ],
        ]));
    }

    /**
     * Request historic rates from a single currency to multiple currencies for a specific date and time.
     *
     * @param \DateTime $dateTime Date and time to request rates for. The time portion is only applicable to LIVE packages and for the last 24 hours
     * @param null $from
     * @param null $to
     * @param int $amount Amount to convert
     * @param bool $obsolete true to request rates for obsolete currencies, false otherwise
     * @param bool $inverse true to request inverse rates as well, false otherwise
     * @param array $options Guzzle request options
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function historicRate(\DateTime $dateTime, $from = null, $to = null, $amount = 1, $obsolete = false, $inverse = false, array $options = [])
    {
        return $this->send($this->historicRateRequest, array_merge($options, [
            RequestOptions::QUERY => [
                'date' => $dateTime->format('Y-m-d'),
                'time' => $dateTime->format('H:i'),
                'from' => $from ?: ('USD'),
                'to' => $to ?: '*',
                'amount' => $amount,
                'obsolete' => $obsolete ? 'true' : 'false',
                'inverse' => $inverse ? 'true' : 'false',
            ],
        ]));
    }

    /**
     * Request historic rates from a single currency to multiple currencies for a time period.
     *
     * @param \DateTime|null $startDateTime Date and time to start requesting rates for. The time portion is only applicable to LIVE packages. Defaults to the current date and time
     * @param \DateTime|null $endDateTime Date and time to end requesting rates for. The time portion is only applicable to LIVE packages. Defaults to the current date and time
     * @param int $amount Amount to convert
     * @param string $interval Either "daily" or "live". Only applicable to LIVE packages
     * @param bool $obsolete true to request rates for obsolete currencies, false otherwise
     * @param bool $inverse true to request inverse rates as well, false otherwise
     * @param int $page Page number of results to return
     * @param int $perPage Number of results per page. The maximum results per page is 100
     * @param array $options Guzzle request options
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function historicRatePeriod(\DateTime $startDateTime = null, \DateTime $endDateTime = null, $from = null, $to = null, $amount = 1, $interval = Interval::DAILY, $obsolete = false, $inverse = false, $page = 1, $perPage = 30, array $options = [])
    {
        return $this->send($this->historicRatePeriodRequest, array_merge($options, [
            RequestOptions::QUERY => [
                'start_timestamp' => isset($startDateTime) ? $startDateTime->format(\DateTime::ISO8601) : null,
                'end_timestamp' => isset($endDateTime) ? $endDateTime->format(\DateTime::ISO8601) : null,
                'from' => $from ?: ('USD'),
                'to' => $to ?: ('USD'),
                'amount' => $amount,
                'interval' => $interval,
                'obsolete' => $obsolete ? 'true' : 'false',
                'inverse' => $inverse ? 'true' : 'false',
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]));
    }

    /**
     * Request monthly averages from a single currency to multiple currencies.
     *
     * @param int|null $year Year to request averages for. Defaults to the current year
     * @param int|null $month Month to request averages for. Defaults to all months
     * @param null $from
     * @param null $to
     * @param int $amount Amount to convert
     * @param bool $obsolete true to request rates for obsolete currencies, false otherwise
     * @param bool $inverse true to request inverse rates as well, false otherwise
     * @param array $options Guzzle request options
     *
     * @return ResponseInterface
     *
     * @throws GuzzleException
     */
    public function monthlyAverage($year = null, $month = null, $from = null, $to = null, $amount = 1, $obsolete = false, $inverse = false, array $options = [])
    {
        return $this->send($this->monthlyAverageRequest, array_merge($options, [
            RequestOptions::QUERY => [
                'from' => $from ?: ('USD'),
                'to' => $to ?: '*',
                'amount' => $amount,
                'year' => $year,
                'month' => $month,
                'obsolete' => $obsolete ? 'true' : 'false',
                'inverse' => $inverse ? 'true' : 'false',
            ],
        ]));
    }

    /**
     * Send an HTTP request.
     *
     * @param RequestInterface $request Request to send
     * @param array $options Request options to apply to the given request and to the transfer
     *
     * @return ResponseInterface
     * @throws GuzzleException
     */
    protected function send(RequestInterface $request, array $options = [])
    {
        return $this->client->send($request, $options);
    }

    /**
     * Send an HTTP request.
     *
     * @param RequestInterface $request Request to send
     * @param array            $options Request options to apply to the given request and to the transfer
     *
     * @return PromiseInterface
     */
    protected function sendAsync(RequestInterface $request, array $options = [])
    {
        return $this->client->sendAsync($request, $options);
    }

    public static function mapRequest()
    {
        return \GuzzleHttp\Middleware::mapRequest(function (RequestInterface $request) {
            // Add api version into url.
            $request = $request->withUri($request->getUri()->withPath("v1{$request->getUri()->getPath()}"));

            // Add format into url.
            $request = $request->withUri($request->getUri()->withPath("{$request->getUri()->getPath()}.json"));

            return $request;
        });
    }

    public function downloadAllCurrencyRates () {
        $lists = $this->getCurrencyList();
        if ($lists) {
            $i = 1;
            foreach ($lists as $item) {
                GetCurrencyRatesJob::dispatch($item)->delay($i++);
            }
        }
    }
    public function downloadCurrencyRates ($currency) {
        if ($this->getCurrencyList()) {
                GetCurrencyRatesJob::dispatch($currency);
        }
    }

    /**
     * getCurrencyList save to redis
     * @return array
     * @throws GuzzleException
     */
    public function getCurrencyList () {
        if (Redis::exists(Consts::LIST_CURRENCY_KEY)) {
            $lists = unserialize(Redis::get(Consts::LIST_CURRENCY_KEY));
            return ($lists);
        }
        try {
            $currencies = $this->currencies();
            $res = json_decode($currencies->getBody()->getContents());
            $list = [];
            foreach ($res->currencies as $currency) {
                $list[] = $currency->iso;
            }
            Redis::del(Consts::LIST_CURRENCY_KEY);
            Redis::set(Consts::LIST_CURRENCY_KEY, serialize($list));

        } catch (\Exception $e) {
            Log::error('error when get currency list '.$e->getMessage());
            return [];
        }
        return $list;
    }
}
