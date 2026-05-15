<?php

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

it('defaults tables to striped rows', function () {
    $table = Table::make($this->createMock(HasTable::class));

    expect($table->isStriped())->toBeTrue();
});
