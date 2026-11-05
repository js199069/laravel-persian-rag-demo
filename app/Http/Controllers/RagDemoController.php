<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Js199069\PersianRag\Rag;
use Throwable;

class RagDemoController extends Controller
{
    private const ROLE_LABELS = [
        'bachelor' => 'دانشجوی کارشناسی',
        'phd' => 'دانشجوی دکتری',
        'faculty' => 'عضو هیئت علمی',
        'staff' => 'کارمند آموزش',
    ];

    private const EXAMPLES = [
        ['label' => 'مرجع رسیدگی به تخلفات دانشجویان', 'q' => 'مرجع رسیدگی به تخلفات انضباطی دانشجویان کیست؟', 'role' => 'bachelor'],
        ['label' => 'یک واحد نظری چند جلسه است؟', 'q' => 'یک واحد درس نظری چند جلسه آموزش است؟', 'role' => 'bachelor'],
        ['label' => 'آزمون جامع دکتری', 'q' => 'آزمون جامع دکتری در سال چند بار برگزار می‌شود؟', 'role' => 'phd'],
        ['label' => 'تخلف مواد مخدر (هیئت علمی)', 'q' => 'تهیه یا مصرف مواد مخدر توسط عضو هیئت علمی چه نوع تخلفی است؟', 'role' => 'faculty'],
        ['label' => 'همان سؤال، با نقش دانشجو', 'q' => 'تهیه یا مصرف مواد مخدر توسط عضو هیئت علمی چه نوع تخلفی است؟', 'role' => 'bachelor'],
        ['label' => 'سؤالی که جوابش در اسناد نیست', 'q' => 'حقوق اعضای هیئت علمی چه روزی از ماه پرداخت می‌شود؟', 'role' => 'faculty'],
    ];

    private const UNAVAILABLE = 'سرویس جست‌وجو در دسترس نیست. مطمئن شوید Ollama روشن است (php artisan rag:health).';

    public function __construct(private Rag $rag) {}

    public function index()
    {
        return view('rag-demo', [
            'roles' => array_intersect_key(self::ROLE_LABELS, config('persian-rag.roles', [])),
            'examples' => self::EXAMPLES,
            'model' => config('persian-rag.answer.provider', 'ollama_local'),
        ]);
    }

    /** مرحله‌ی ۱: جست‌وجو؛ منبع‌ها فوراً به مرورگر می‌روند */
    public function search(Request $request)
    {
        $question = $this->question($request);
        $role = $this->role($request);

        try {
            $prepared = $this->rag->prepare($question, $role);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => self::UNAVAILABLE], 503);
        }

        return response()->json([
            'refused' => $prepared['refused'],
            'refusal' => $prepared['refusal'],
            'best_similarity' => round($prepared['best_similarity'], 3),
            'search_ms' => $prepared['search_ms'],
            'model' => config('persian-rag.answer.provider', 'ollama_local'),
            'sources' => collect($prepared['sources'])->map(fn ($s, $n) => $s + ['n' => $n])->values(),
        ]);
    }

    /** مرحله‌ی ۲: جواب، به‌صورت استریم */
    public function answer(Request $request)
    {
        $question = $this->question($request);
        $role = $this->role($request);

        try {
            $prepared = $this->rag->prepare($question, $role);
        } catch (Throwable $e) {
            report($e);
            abort(503, self::UNAVAILABLE);
        }

        abort_if($prepared['refused'], 422, 'سند مرتبطی پیدا نشد');

        return $this->rag->stream($prepared)->usingVercelDataProtocol();
    }

    private function question(Request $request): string
    {
        $question = trim((string) $request->query('q', ''));
        abort_if($question === '' || mb_strlen($question) > 500, 422, 'سؤال نامعتبر');

        return $question;
    }

    /** در دمو نقش از مرورگر می‌آید؛ در نرم‌افزار واقعی باید از کاربر واردشده خوانده شود */
    private function role(Request $request): ?string
    {
        $role = (string) $request->query('role', '');
        if ($role === '') {
            return null;
        }

        abort_unless(array_key_exists($role, config('persian-rag.roles', [])), 422, 'نقش نامعتبر');

        return $role;
    }
}
