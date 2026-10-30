<?php
namespace GTMAuditor;

require_once __DIR__ . '/AuditDataSource.php';
require_once __DIR__ . '/SourceStatus.php';

// ─── Live audit data collection ──────────────────────────────────────────────
/**
 * Pulls live GTM and GA4 data for one audit and records a status per source.
 * A source that is missing or failed never reports SourceStatus::SUCCESS, so it
 * cannot produce a clean score.
 */
final class AuditDataCollector
{
    public const GA4_WINDOW_DAYS = 30;

    private const DETAIL_MAX_LENGTH = 200;

    /**
     * @param array{gtm: ?array, gtm_reason: string, ga4_property_id: ?string, ga4_reason: string} $target
     * @return array{gtm: array, ga4: array, source_status: array}
     */
    public function collect(AuditDataSource $source, array $target): array
    {
        $gtmData  = [];
        $gtmState = $this->collectGtm($source, $target);
        if ($gtmState['status'] === SourceStatus::SUCCESS) {
            $gtmData = $gtmState['data'];
        }

        $ga4Data  = [];
        $ga4State = $this->collectGa4($source, $target);
        if ($ga4State['status'] === SourceStatus::SUCCESS) {
            $ga4Data = $ga4State['data'];
        }

        return [
            'gtm' => $gtmData,
            'ga4' => $ga4Data,
            'source_status' => [
                'gtm'      => ['status' => $gtmState['status'], 'detail' => $gtmState['detail']],
                'ga4'      => ['status' => $ga4State['status'], 'detail' => $ga4State['detail']],
                'bigquery' => ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => ''],
                'crawl'    => ['status' => SourceStatus::NOT_APPLICABLE, 'detail' => ''],
            ],
        ];
    }

    /** @return array{status: string, detail: string, data: array} */
    private function collectGtm(AuditDataSource $source, array $target): array
    {
        if (empty($target['gtm'])) {
            return $this->missingTarget((string) ($target['gtm_reason'] ?? ''));
        }

        try {
            $data = $source->liveContainerVersion(
                (string) $target['gtm']['account_id'],
                (string) $target['gtm']['container_id']
            );
            return ['status' => SourceStatus::SUCCESS, 'detail' => '', 'data' => $data];
        } catch (\Throwable $e) {
            return $this->failed($e);
        }
    }

    /** @return array{status: string, detail: string, data: array} */
    private function collectGa4(AuditDataSource $source, array $target): array
    {
        $propertyId = $target['ga4_property_id'] ?? null;
        if ($propertyId === null || $propertyId === '') {
            return $this->missingTarget((string) ($target['ga4_reason'] ?? ''));
        }

        $propertyId = (string) $propertyId;
        $days = self::GA4_WINDOW_DAYS;

        try {
            $landingRows = $source->ga4Report($propertyId, ['landingPage'], ['sessions'], $days, 50);

            $totalRows     = $source->ga4Report($propertyId, [], ['sessions'], $days, 1);
            $totalSessions = (int) ($totalRows[0]['sessions'] ?? 0);

            $purchaseRows = $source->ga4Report($propertyId, ['transactionId'], ['ecommercePurchases'], $days, 100);
            $pageRows     = $source->ga4Report($propertyId, ['pageLocation'], ['screenPageViews'], $days, 200);
            $hostRows     = $source->ga4Report($propertyId, ['hostName'], ['sessions'], $days, 50);
        } catch (\Throwable $e) {
            return $this->failed($e);
        }

        return [
            'status' => SourceStatus::SUCCESS,
            'detail' => '',
            'data'   => [
                'landing_page'          => $landingRows,
                'total_sessions'        => $totalSessions,
                'purchase_transactions' => $purchaseRows,
                'page_location'         => $pageRows,
                'hostname'              => $hostRows,
            ],
        ];
    }

    /**
     * No target means the source could not be queried. Only an unselected target is
     * a coverage gap; anything else (not connected, unknown) is unavailable.
     *
     * @return array{status: string, detail: string, data: array}
     */
    private function missingTarget(string $reason): array
    {
        $status = $reason === 'target_not_selected'
            ? SourceStatus::INSUFFICIENT_COVERAGE
            : SourceStatus::UNAVAILABLE;

        return ['status' => $status, 'detail' => $reason, 'data' => []];
    }

    /** @return array{status: string, detail: string, data: array} */
    private function failed(\Throwable $e): array
    {
        $message = $e->getMessage();
        $detail  = function_exists('mb_substr')
            ? mb_substr($message, 0, self::DETAIL_MAX_LENGTH, 'UTF-8')
            : substr($message, 0, self::DETAIL_MAX_LENGTH);

        return ['status' => SourceStatus::fromThrowable($e), 'detail' => $detail, 'data' => []];
    }
}
