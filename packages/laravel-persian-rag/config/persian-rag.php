<?php

return [
    // هر نقش کاربر، اسناد کدام گروه‌های مخاطب را می‌تواند ببیند
    'roles' => [
        'bachelor' => ['bachelor', 'all_students'],
        'phd' => ['phd', 'all_students'],
        'faculty' => ['faculty', 'phd', 'bachelor', 'all_students'],
        'staff' => ['staff', 'phd', 'bachelor', 'all_students'],
    ],

    // نام خوانای هر گروه مخاطب؛ بخشی از زمینه‌ی embedding هر تکه است،
    // پس تغییرش یعنی ساختن دوباره‌ی ایندکس (rag:index --fresh)
    'audience_labels' => [
        'faculty' => 'اعضای هیئت علمی',
        'phd' => 'دانشجویان دکتری',
        'bachelor' => 'دانشجویان کارشناسی',
        'all_students' => 'همه‌ی دانشجویان',
        'staff' => 'کارکنان آموزش',
    ],

    // لایه‌ی ۱ «جواب نده»: زیر این شباهت، مدل زبانی صدا زده نمی‌شود
    'min_similarity' => 0.55,

    'embedding' => [
        // embedding سؤال و اسناد باید با یک مدل ساخته شوند؛ عوض کردنش یعنی ایندکس دوباره
        'provider' => env('RAG_EMBED_PROVIDER', 'ollama_local'),
        'model' => env('OLLAMA_EMBED_MODEL', 'bge-m3'),
        'dimensions' => 1024,
    ],

    'search' => [
        'candidates' => 20,          // کاندید از هر روش، قبل از ترکیب
        'max_per_document' => 2,     // تنوع: حداکثر تکه از هر سند
        'max_term_share' => 0.2,     // کلمه‌ی حاضر در بیش از این سهم از تکه‌ها، در جست‌وجوی متنی نادیده گرفته می‌شود
        'rrf_k' => 60,               // ثابت Reciprocal Rank Fusion
        // کلمه‌های پرسشی و فعل‌های عمومی که در سؤال هستند ولی در جواب نه
        'question_words' => [
            'چیست', 'کیست', 'چند', 'چگونه', 'چطور', 'کدام', 'کجا', 'کجاست', 'آیا',
            'دارد', 'دارند', 'است', 'هست', 'باید', 'شود', 'میشود', 'نوع',
        ],
    ],

    'answer' => [
        'provider' => env('RAG_LLM_PROVIDER', 'ollama_local'),
        'k' => 3,
        'timeout' => 300,
        'refusal' => 'پاسخ این سؤال در اسناد موجود نیامده است.',
    ],

    'extraction' => [
        'pdftotext' => env('PDFTOTEXT_BIN', 'pdftotext'),
        'pdftoppm' => env('PDFTOPPM_BIN', 'pdftoppm'),
        'tesseract' => env('TESSERACT_BIN', 'tesseract'),
        'ocr_language' => 'fas',
        'ocr_dpi' => 300,
        'ocr_tmp_path' => storage_path('app/ocr-tmp'),
    ],

    'corpus_path' => storage_path('app/corpus'),
];
