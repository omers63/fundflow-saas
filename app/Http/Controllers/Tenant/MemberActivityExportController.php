<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\MemberActivityFeedService;
use App\Support\Insights\InsightFormatter;
use App\Support\MemberLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberActivityExportController extends Controller
{
    public function __invoke(Request $request, MemberActivityFeedService $feed): StreamedResponse
    {
        $member = $request->user('tenant')?->member;

        if ($member === null) {
            abort(403);
        }

        $user = $request->user('tenant');

        $render = function () use ($request, $feed, $member): StreamedResponse {
            $validated = $request->validate([
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after_or_equal:from'],
                'filter' => ['nullable', 'string'],
            ]);

            $from = Carbon::parse($validated['from']);
            $to = Carbon::parse($validated['to']);
            $filter = $validated['filter'] ?? MemberActivityFeedService::FILTER_ALL;

            $rows = $feed->exportQuery($member, $from, $to, $filter)->get();
            $currency = InsightFormatter::currency();
            $filename = 'member-activity-'.$member->member_number.'-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.csv';

            return response()->streamDownload(function () use ($feed, $rows, $currency): void {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $feed->exportCsvHeaders());

                foreach ($rows as $transaction) {
                    fputcsv($out, $feed->mapExportRow($transaction, $currency));
                }

                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
        };

        return $user !== null
            ? MemberLocale::using($user, $render)
            : $render();
    }
}
