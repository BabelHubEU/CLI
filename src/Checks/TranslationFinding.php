<?php

declare(strict_types=1);

namespace BabelHub\Cli\Checks;

/**
 * Ustalenie kontroli czytającej **katalog tłumaczeń** (a nie kod): `quality`, `coverage`,
 * `acceptance`.
 *
 * Powstaje na dwa sposoby i to jest cała jego rola:
 *
 * * **z odpowiedzi serwisu** (`fromApi`) — wtedy jest przepisaniem ustalenia QA, bez własnej
 *   interpretacji; kod (`missing`, `placeholder_missing`, …) zostaje ten sam, bo to on jest
 *   kontraktem, po którym rozgałęzia się CI;
 * * **z danych, których serwis nie wysyła jako ustalenia** (`unapproved`) — kontrola
 *   `acceptance` liczy je z liczników statusów w `GET …/status`, bo QA liczy ustalenia wobec
 *   **zatwierdzonych** wartości i nie odróżnia „nikt nie przetłumaczył” od „tłumaczenie czeka
 *   na zatwierdzenie”. Kod `unapproved` jest więc kodem **CLI**, nie serwisu — i dlatego jest
 *   tu nazwany stałą, a nie wpisany w zdanie.
 *
 * **Waga nieznana zostaje ostrzeżeniem.** Serwis ma dziś trzy wagi, ale CLI i serwis żyją
 * w różnych cyklach wydawniczych: gdy serwis dołoży czwartą, stary klient nie może ani
 * wywalić się na `tryFrom() === null`, ani zablokować merge'a w cudzym repozytorium. Ustalenie
 * zachowuje **surową** wagę w JSON-ie (`severity`), a do bramki wchodzi jako `warning` —
 * czyli widać je w logu i w raporcie, ale domyślny próg go nie blokuje.
 */
final readonly class TranslationFinding implements FindingInterface
{
    /** Kod ustalenia kontroli `acceptance` — wartość jest, ale nie jest zatwierdzona. */
    public const UNAPPROVED = 'unapproved';

    /**
     * @param string $severityRaw waga **tak, jak przyszła z serwisu** (albo nasza, dla `unapproved`)
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $code,
        public string $severityRaw,
        public ?string $key,
        public ?string $locale,
        public string $message,
        public array $params = [],
    ) {
    }

    /**
     * Ustalenie z odpowiedzi serwisu.
     *
     * Brakujące pola nie przewracają kontroli: `key` i `locale` bywają `null` (ustalenie może
     * dotyczyć całego języka), a waga bez znanego przypadku staje się ostrzeżeniem —
     * patrz komentarz klasy.
     *
     * @param array<string, mixed> $row
     */
    public static function fromApi(array $row): self
    {
        $params = $row['params'] ?? [];

        return new self(
            code: is_string($row['code'] ?? null) ? $row['code'] : 'unknown',
            severityRaw: is_string($row['severity'] ?? null) ? $row['severity'] : '',
            key: is_string($row['key'] ?? null) ? $row['key'] : null,
            locale: is_string($row['locale'] ?? null) ? $row['locale'] : null,
            message: is_string($row['message'] ?? null) ? $row['message'] : '',
            params: is_array($params) ? $params : [],
        );
    }

    /**
     * Ustalenie kontroli `acceptance`: w języku są tłumaczenia, które nie weszły do katalogu.
     *
     * Zdanie mówi wprost, **co z tym zrobić** (zatwierdzić w panelu), bo inaczej „5
     * niezatwierdzonych” czyta się jak błąd tłumaczenia, a to zwykle praca w toku: wartość
     * została zapisana, ale nikt jej jeszcze nie zatwierdził — więc konsument jej nie widzi.
     */
    public static function unapproved(string $locale, int $draft, int $translated, int $review): self
    {
        $parts = [];
        foreach (['szkic' => $draft, 'przetłumaczone' => $translated, 'do przeglądu' => $review] as $label => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%s: %d', $label, $count);
            }
        }

        $total = $draft + $translated + $review;

        return new self(
            code: self::UNAPPROVED,
            severityRaw: CheckSeverity::Warning->value,
            key: null,
            locale: $locale,
            message: sprintf(
                'W %s %d tłumaczeń nie jest zatwierdzonych (%s). Zatwierdź je w panelu, żeby trafiły do katalogu.',
                $locale,
                $total,
                implode(', ', $parts),
            ),
            params: [
                'locale' => $locale,
                'draft' => $draft,
                'translated' => $translated,
                'review' => $review,
            ],
        );
    }

    public function code(): string
    {
        return $this->code;
    }

    public function severity(): CheckSeverity
    {
        return CheckSeverity::tryFrom($this->severityRaw) ?? CheckSeverity::Warning;
    }

    /**
     * Miejsce ustalenia: `język klucz`, sam język (gdy ustalenie dotyczy całego języka)
     * albo `—`.
     *
     * Język **musi** tu być, choć wcześniej go nie było: ten sam brakujący klucz w dwóch językach
     * dawał dwie identyczne linie, których nie dało się odróżnić w logu CI — a to jest dokładnie
     * ta informacja, po którą człowiek tam przychodzi.
     */
    public function location(): string
    {
        $parts = array_values(array_filter([$this->locale, $this->key], static fn (?string $v): bool => $v !== null && $v !== ''));

        return $parts === [] ? '—' : implode(' ', $parts);
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            // Surowa waga z serwisu: `severity()` tłumaczy ją na bramkę, ale w JSON-ie ma być to,
            // co przyszło — inaczej nieznana waga wyglądałaby jak ostrzeżenie i nikt by się
            // nie dowiedział, że serwis mówi coś nowego.
            'severity' => $this->severityRaw,
            'key' => $this->key,
            'locale' => $this->locale,
            'message' => $this->message,
            // `(object)`, nie tablica: pusta mapa musi zostać `{}` w JSON-ie. Kontrakt API mówi
            // `params` jako obiekt, a `[]` po ponownym zakodowaniu byłoby pustą **listą** —
            // ten sam błąd, przed którym broni `Printer::jsonBody()`.
            'params' => (object) $this->params,
        ];
    }
}
