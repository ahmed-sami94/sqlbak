<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
sqlbak_page_start('لوحة التحكم', 'dashboard');
?>
<section class="dashboard-hero"><div><span class="hero-kicker"><i class="fa fa-shield"></i> SQLBak Operations</span><h2>صحة النسخ الاحتياطية في شاشة واحدة</h2><p>إحصاءات مباشرة، اتصال الوجهات، وفشل المهام مع رمز تتبع واضح.</p></div><div class="hero-meta"><span><i class="fa fa-clock-o"></i> تحديث تلقائي كل 30 ثانية</span><span data-dashboard-time>-</span></div></section>
<section class="stats-grid dashboard-stats">
    <article class="stat-card"><span class="stat-icon"><i class="fa fa-database"></i></span><div><small>قواعد مفعلة</small><strong data-stat="databases">-</strong></div></article>
    <article class="stat-card"><span class="stat-icon"><i class="fa fa-server"></i></span><div><small>وجهات مفعلة</small><strong data-stat="destinations">-</strong></div></article>
    <article class="stat-card"><span class="stat-icon"><i class="fa fa-check-circle"></i></span><div><small>نسبة النجاح 24 ساعة</small><strong><span data-stat="success_rate">-</span>%</strong></div></article>
    <article class="stat-card"><span class="stat-icon"><i class="fa fa-exclamation-triangle"></i></span><div><small>تحتاج متابعة</small><strong data-stat="failed">-</strong></div></article>
    <article class="stat-card"><span class="stat-icon"><i class="fa fa-database"></i></span><div><small>حجم 7 أيام</small><strong data-stat-bytes>-</strong></div></article>
</section>
<section class="dashboard-grid">
    <article class="panel chart-panel"><div class="panel-head"><div><h2>اتجاه النسخ خلال 14 يوماً</h2><p>ناجحة، جزئية، وفاشلة.</p></div></div><canvas id="backupTrend" height="125"></canvas></article>
    <article class="panel chart-panel"><div class="panel-head"><div><h2>الحجم حسب الوجهة</h2><p>آخر 30 يوماً.</p></div></div><canvas id="destinationVolume" height="125"></canvas></article>
</section>
<section class="grid-two">
    <article class="panel"><div class="panel-head"><div><h2>صحة الوجهات</h2><p>حالة حقيقية مبنية على اختبار الكتابة.</p></div><a class="button secondary" href="storage.php">إدارة</a></div><div class="health-list" data-destination-health><div class="loading-state"><i class="fa fa-spinner fa-spin"></i> جارٍ التحميل</div></div></article>
    <article class="panel"><div class="panel-head"><div><h2>آخر الأخطاء</h2><p>السبب ورمز التتبع.</p></div><a class="button secondary" href="logs.php">كل الأخطاء</a></div><div class="failure-list" data-failure-list><div class="loading-state"><i class="fa fa-spinner fa-spin"></i> جارٍ التحميل</div></div></article>
</section>
<script src="assets/vendor/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js?v=20260711"></script>
<?php sqlbak_page_end();
