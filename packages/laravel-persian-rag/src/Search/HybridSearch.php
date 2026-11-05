<?php

namespace Js199069\PersianRag\Search;

use Illuminate\Support\Facades\DB;
use Js199069\PersianRag\Models\RagChunk;
use Js199069\PersianRag\Support\PersianNormalizer;
use Js199069\PersianRag\Contracts\Embedder;

final class HybridSearch
{
    /** کلمه‌های پرسشی و فعل‌های عمومی که در سؤال هستند ولی در جواب نه */
    public const DEFAULT_QUESTION_WORDS = [
        'چیست', 'کیست', 'چند', 'چگونه', 'چطور', 'کدام', 'کجا', 'کجاست', 'آیا',
        'دارد', 'دارند', 'است', 'هست', 'باید', 'شود', 'میشود', 'نوع',
    ];

    /**
     * @param  float  $maxTermShare  کلمه‌ای که در بیش از این سهم از تکه‌ها آمده، برای جست‌وجوی متنی تقریباً بی‌اطلاع است
     * @param  string[]  $questionWords
     */
    public function __construct(
        private string $provider = 'ollama_local',
        private int $candidates = 20,
        private int $maxPerDocument = 2,
        private float $maxTermShare = 0.2,
        private int $rrfK = 60,
        private array $questionWords = self::DEFAULT_QUESTION_WORDS,
        private ?Embedder $embedder = null,
    ) {}

    /**
     * @param  string[]|null  $audiences  گروه‌های مخاطب مجاز؛ null یعنی بدون فیلتر
     * @return array{results: array, best_similarity: float, text_query: string}
     */
    public function search(string $question, ?array $audiences = null, int $k = 5): array
    {
        $normalized = PersianNormalizer::canonical($question);
        $vector = ($this->embedder ?? app(Embedder::class))->embed([$normalized], $this->provider)[0];

        // فیلتر دسترسی داخل خود کوئری‌ها، نه بعد از آن
        $scope = fn ($query) => $query->when(
            $audiences !== null,
            fn ($q) => $q->whereHas('document', fn ($d) => $d->whereIn('audience', $audiences)),
        );

        // ۱. جست‌وجوی برداری
        $vectorHits = $scope(RagChunk::query())
            ->select('id')
            ->selectVectorDistance('embedding', $vector, as: 'distance')
            ->orderByVectorDistance('embedding', $vector)
            ->limit($this->candidates)
            ->get();

        // ۲. جست‌وجوی متنی با «یا»، فقط با کلمه‌های پرمعنا
        $textHits = collect();
        $tsquery = $this->orQuery($question);
        if ($tsquery !== '') {
            $textHits = $scope(RagChunk::query())
                ->select('id')
                ->selectRaw("ts_rank_cd(tsv, to_tsquery('simple', ?)) AS rank", [$tsquery])
                ->whereRaw("tsv @@ to_tsquery('simple', ?)", [$tsquery])
                ->orderByDesc('rank')
                ->limit($this->candidates)
                ->get();
        }

        // ۳. ترکیب با Reciprocal Rank Fusion
        $scores = [];
        foreach ([$vectorHits->pluck('id'), $textHits->pluck('id')] as $list) {
            foreach ($list->values() as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / ($this->rrfK + $rank + 1);
            }
        }
        arsort($scores);

        // ۴. تنوع: حداکثر N تکه از هر سند، تا یک سند بلند همه‌ی نتایج را نگیرد
        $documentOf = RagChunk::whereIn('id', array_keys($scores))->pluck('rag_document_id', 'id');
        $ids = [];
        $perDocument = [];
        foreach (array_keys($scores) as $id) {
            $document = $documentOf[$id];
            if (($perDocument[$document] ?? 0) >= $this->maxPerDocument) {
                continue;
            }
            $perDocument[$document] = ($perDocument[$document] ?? 0) + 1;
            $ids[] = $id;
            if (count($ids) >= $k) {
                break;
            }
        }

        $chunks = RagChunk::with('document')->findMany($ids)->keyBy('id');
        $similarity = $vectorHits->mapWithKeys(fn ($hit) => [$hit->id => 1 - (float) $hit->distance]);
        $textIds = $textHits->pluck('id')->flip();

        $results = [];
        foreach ($ids as $id) {
            $results[] = [
                'chunk' => $chunks[$id],
                'rrf' => $scores[$id],
                'similarity' => $similarity[$id] ?? null,
                'in_vector' => isset($similarity[$id]),
                'in_text' => isset($textIds[$id]),
            ];
        }

        return [
            'results' => $results,
            'best_similarity' => $vectorHits->isEmpty() ? 0.0 : 1 - (float) $vectorHits->first()->distance,
            'text_query' => $tsquery,
        ];
    }

    /** tsquery با «یا» از کلمه‌های سه‌حرفی به بالا و عددها، بدون کلمه‌های خیلی پرتکرار؛ فقط حروف و ارقام، پس امن است */
    private function orQuery(string $question): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', PersianNormalizer::searchForm($question), $matches);

        $terms = array_values(array_filter(
            array_unique($matches[0]),
            fn ($t) => mb_strlen($t) >= 3 || (ctype_digit($t) && strlen($t) >= 2),
        ));

        $terms = array_values(array_diff($terms, $this->questionWords));

        if ($terms === []) {
            return '';
        }

        $total = max(1, RagChunk::count());
        $informative = array_values(array_filter($terms, function ($term) use ($total) {
            $count = DB::table('rag_chunks')->whereRaw("tsv @@ to_tsquery('simple', ?)", [$term])->count();

            return $count / $total <= $this->maxTermShare;
        }));

        // اگر همه‌ی کلمه‌ها پرتکرار بودند، با همه جست‌وجو کن
        return implode(' | ', $informative !== [] ? $informative : $terms);
    }
}
