<?php

declare(strict_types=1);

namespace BabelHub\Cli\Application;

/**
 * Co `pull` zrobił z jednym językiem — jedna wartość, trzy stany i powód, dla którego muszą
 * być rozróżnialne.
 *
 * `Skipped` (serwis odpowiedział `304`) jest tu najważniejszy: to on sprawia, że `pull`
 * uruchamiany po każdym commicie **nie dotyka plików**, więc w repozytorium nie ma diffu,
 * gdy nic się nie zmieniło. Bez tego każdy przebieg przepisywałby pliki (choćby inną kolejnością
 * kluczy) i code review tonęłoby w szumie — a to jest dokładnie ten ból, który plan opisuje
 * przy okazji 1.16b.
 */
enum PullOutcome: string
{
    case Written = 'written';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Drifted = 'drifted';

    /**
     * Serwis nie ma ani jednego zatwierdzonego tekstu w tym języku.
     *
     * Osobny stan, bo **nie wolno wtedy pisać**: gdyby `pull` zapisał pusty plik, skasowałby
     * pracę, której nikt jeszcze nie zatwierdził (albo teksty przygotowane lokalnie). Pusty
     * katalog to nie „pusty plik”, a „nie ma czego pobierać”.
     */
    case Empty = 'empty';
}
