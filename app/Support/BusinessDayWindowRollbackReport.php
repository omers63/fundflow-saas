<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

final class BusinessDayWindowRollbackReport
{
    /**
     * @param  list<string>  $blockers
     * @param  list<array{key: string, heading: string, events: list<array{id: string, title: string, amount: ?string, date: ?string, status: ?string, detail: ?string, meta: string}>}>  $sections
     */
    public function __construct(
        public Carbon $asOf,
        public bool $dryRun,
        public bool $blocked,
        public array $blockers,
        public int $contributions = 0,
        public int $installments = 0,
        public int $deposits = 0,
        public int $cashOuts = 0,
        public int $disbursements = 0,
        public int $manualJournals = 0,
        public int $applications = 0,
        public int $otherSources = 0,
        public int $earlySettlements = 0,
        public int $withdrawals = 0,
        public int $freezes = 0,
        public int $freezeTicks = 0,
        public int $guarantorTransfers = 0,
        public int $bankMatches = 0,
        public int $statements = 0,
        public int $futureCycleRows = 0,
        public int $overdueResets = 0,
        public int $ledgerLinesReversed = 0,
        public array $sections = [],
    ) {}

    /**
     * @return list<string>
     */
    public function eventIds(): array
    {
        return collect($this->sections)
            ->flatMap(fn (array $section): array => array_column($section['events'], 'id'))
            ->values()
            ->all();
    }

    public function summary(): string
    {
        if ($this->blocked) {
            return __('Blocked: :reasons', ['reasons' => implode(' ', $this->blockers)]);
        }

        return __('Contributions :c · EMIs :e · Deposits :d · Cash-outs :x · Disbursements :y · Manual journals :j · Applications :a · Other sources :r · Early settlements :p · Withdrawals :w · Freezes :z · Freeze ticks :k · Guarantor transfers :g · Bank matches :b · Statements :s · Future cycles :f · Overdue resets :o · Ledger lines :l', [
            'c' => $this->contributions,
            'e' => $this->installments,
            'd' => $this->deposits,
            'x' => $this->cashOuts,
            'y' => $this->disbursements,
            'j' => $this->manualJournals,
            'a' => $this->applications,
            'r' => $this->otherSources,
            'p' => $this->earlySettlements,
            'w' => $this->withdrawals,
            'z' => $this->freezes,
            'k' => $this->freezeTicks,
            'g' => $this->guarantorTransfers,
            'b' => $this->bankMatches,
            's' => $this->statements,
            'f' => $this->futureCycleRows,
            'o' => $this->overdueResets,
            'l' => $this->ledgerLinesReversed,
        ]);
    }
}
