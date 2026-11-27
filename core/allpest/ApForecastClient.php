<?php
declare(strict_types=1);

require_once __DIR__ . '/ApTemperature.php';

class ApForecastUnavailable extends RuntimeException {}

/** Fetches an hourly forecast from Open-Meteo. The HTTP transport and clock are injected so tests never touch the network. */
final class ApForecastClient
{
    /** @var callable(string):string */
    private $http;
    /** @var callable():DateTimeImmutable */
    private $clock;

    /**
     * @param callable(string):string $http returns the response body or throws
     * @param null|callable():DateTimeImmutable $clock
     */
    public function __construct(callable $http, ?callable $clock = null)
    {
        $this->http = $http;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return array{source:string,retrieved_at:string,hourly:array<int,array{time:string,temp:float}>} */
    public function fetch(float $lat, float $lng, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException('Coordinates are out of range.');
        }
        $tz = new DateTimeZone(ApTemperature::TZ);
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $lat,
            'longitude' => $lng,
            'hourly' => 'temperature_2m',
            'timezone' => ApTemperature::TZ,
            'start_date' => $from->setTimezone($tz)->format('Y-m-d'),
            'end_date' => $to->setTimezone($tz)->format('Y-m-d'),
        ], '', '&', PHP_QUERY_RFC3986);

        try {
            $body = ($this->http)($url);
        } catch (Throwable $e) {
            throw new ApForecastUnavailable('Forecast service unavailable: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode($body, true);
        $times = $data['hourly']['time'] ?? null;
        $temps = $data['hourly']['temperature_2m'] ?? null;
        if (!is_array($times) || !is_array($temps) || count($times) !== count($temps) || !$times) {
            throw new ApForecastUnavailable('Forecast service returned an unexpected response.');
        }
        $hourly = [];
        foreach ($times as $i => $t) {
            $v = $temps[$i];
            if (!is_numeric($v) || $v < ApTemperature::MIN_PLAUSIBLE_C || $v > ApTemperature::MAX_PLAUSIBLE_C) {
                throw new ApForecastUnavailable('Forecast contained an implausible temperature.');
            }
            $hourly[] = ['time' => (string)$t, 'temp' => (float)$v];
        }
        return [
            'source' => $url,
            'retrieved_at' => ($this->clock)()->format(DATE_ATOM),
            'hourly' => $hourly,
        ];
    }
}
