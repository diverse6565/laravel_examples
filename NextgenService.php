<?php

namespace App\Service\Parser\Nextgen;

use App\Jobs\Parsers\Nextgen\NextgenProjectJob;
use App\Jobs\Parsers\ParserNotificationJob;
use App\Models\Parser;
use App\Models\ParserErrors;
use App\Models\ParserProject;
use App\Models\Project;
use App\Service\Parser\Nextgen\Contract\NextgenService as NextgenServiceContract;
use App\Service\Parser\Nextgen\DTO\JobDTO;
use App\Service\Parser\Nextgen\DTO\SettingsDTO;
use App\Service\Parser\Nextgen\Exceptions\NextgenServiceException;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class NextgenService implements NextgenServiceContract
{
    private const LIMIT_JOBS = 450;
    private const SORT_BY_STATUS = 'ng_orders.ng_status';
    private const ORDER_BY = 'asc';
    private const DRAFTING_ORDER_STATUS = 'Drafting';

    private $client;

    private $cookies;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param SettingsDTO $settings
     * @throws NextgenServiceException
     */
    public function execute(SettingsDTO $settings): void
    {
        $this->getLogin($settings);
        $jobs = $this->getJobs($settings);

        $dispatch = false;
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            if ($job['status'] !== self::DRAFTING_ORDER_STATUS || $this->existingProject($job['orderNumber'])) {
                Log::info('Skip order ' . $job['orderNumber'] . ' with status: ' . $job['status']);
                continue;
            }

            if (Project::query()->where('name', $job['orderNumber'])->exists()) {
                Log::info('Skip already exists project: ' . $job['orderNumber']);
                continue;
            }

            $dispatch = true;
            $jobDTO = new JobDTO($job);

            dispatch(new NextgenProjectJob($jobDTO, $settings, $this->cookies));
        }

        if (!$dispatch) {
            dispatch(new ParserNotificationJob($settings));
        }
    }

    /**
     * @param SettingsDTO $settings
     * @throws NextgenServiceException
     */
    public function getLogin(SettingsDTO $settings): void
    {
        try {
            $response = $this->client->post(
                $settings->getLoginURL(),
                [
                    RequestOptions::FORM_PARAMS => [
                        "user"     => $settings->login,
                        "password" => $settings->password,
                    ],
                ]
            );

            $responseArray = json_decode($response->getBody()->getContents(), true);

            if (!data_get($responseArray, 'success', false)) {
                throw new LogicException(data_get($responseArray, 'message'));
            }

            $cookies = $response->getHeader('Set-Cookie');
            if (!$cookies) {
                throw new LogicException('No cookies in url: ' . $settings->getLoginURL());
            }

            $this->cookies = $cookies[0];
        } catch (Throwable $exception) {
            throw new NextgenServiceException($exception->getMessage());
        }
    }

    /**
     * @param SettingsDTO $settings
     * @return array
     * @throws NextgenServiceException
     */
    public function getJobs(SettingsDTO $settings): array
    {
        try {
            $response = $this->client->post(
                $settings->getJobsURL(),
                [
                    RequestOptions::FORM_PARAMS => [
                        'length'         => self::LIMIT_JOBS,
                        'order_by_key'   => self::SORT_BY_STATUS,
                        'order_by_value' => self::ORDER_BY,
                    ],
                    RequestOptions::HEADERS     => [
                        'cookie' => $this->cookies,
                    ],
                ]
            );

            $responseArray = json_decode($response->getBody()->getContents(), true);

            return data_get($responseArray, 'data', []);
        } catch (Throwable $exception) {
            throw new NextgenServiceException($exception->getMessage());
        }
    }

    /**
     * @param string $name
     * @return bool
     */
    private function existingProject(string $name): bool
    {
        return ParserProject::query()->where('project_name', $name)->exists();
    }
}