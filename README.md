# Rank Math Batch SEO Automator

Generic WordPress plugin for safely batch-updating Rank Math SEO metadata on large content sites.

**Author:** Ibrahim.N.I.soliman  
**Email:** ibrahim.noshy@hotmail.com  
**License:** GPL-2.0-or-later

## Features

- Safe Mode: fills missing Rank Math fields only.
- Full Site Mode: updates all eligible published posts and pages.
- Optional skip for posts already completed by Safe Mode.
- Dry Run preview before writing metadata.
- AJAX polling plus WP-Cron continuation for large sites.
- One-time metadata backup before each real write.
- Review Report with changed posts, edit links, changed fields, and old/new values.
- CSV export for review/audit.
- Does not write or fake `rank_math_seo_score`.

## Managed Rank Math fields

- `rank_math_focus_keyword`
- `rank_math_title`
- `rank_math_description`
- `rank_math_robots`
- `rank_math_primary_category`

## Installation

1. Upload `rank-math-batch-seo-automator.zip` from WordPress Admin > Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open Tools > Rank Math Batch SEO.
4. Start with Dry Run enabled.
5. Run Safe Mode first.
6. Review the generated report.
7. Disable Dry Run and run Safe Mode for real.
8. Use Full Site Mode only after reviewing Safe Mode results.

## Arabic quick note

الإضافة عامة وليست مرتبطة بموقع معين. يمكنك استخدامها على أي موقع ووردبريس يستخدم Rank Math. ابدأ دائمًا بخيار Dry Run ثم Safe Mode، وبعد مراجعة النتائج استخدم Full Site Mode عند الحاجة.

## AI providers

This plugin does not require AI providers or API keys. It generates SEO metadata from the post title, excerpt, content, categories, and existing Rank Math fields.
