<?php

declare(strict_types=1);

/**
 * Residual application Arabic strings merged into lang/ar.json
 *
 * @return array<string, string>
 */
return [
    ':active active tiers · :inactive inactive' => ':active شريحة نشطة · :inactive غير نشطة',
    ':count EMI is overdue|:count EMIs are overdue' => ':count قسط متأخر|:count أقساط متأخرة',
    ':count guaranteed loan is past the grace threshold|:count guaranteed loans are past the grace threshold' => ':count قرض مكفول تجاوز فترة السماح|:count قروض مكفولة تجاوزت فترة السماح',
    ':count in this tab · :total total in queue' => ':count في هذا التبويب · :total إجمالي في قائمة الانتظار',
    ':count loan has been transferred to your account|:count loans have been transferred to your account' => ':count قرض تم تحويله إلى حسابك|:count قروض تم تحويلها إلى حسابك',
    ':count member still to collect for :period|:count members still to collect for :period' => ':count عضو لم يُحصَّل بعد لـ :period|:count أعضاء لم يُحصَّلوا بعد لـ :period',
    ':count member with EMIs to collect for :period|:count members with EMIs to collect for :period' => ':count عضو لديه أقساط للتحصيل لـ :period|:count أعضاء لديهم أقساط للتحصيل لـ :period',
    ':count paid installment in the open period|:count paid installments in the open period' => ':count قسط مدفوع في الفترة المفتوحة|:count أقساط مدفوعة في الفترة المفتوحة',
    ':count pending · :amount :currency' => ':count معلّق · :amount :currency',
    ':count posted contribution row|:count posted contribution rows' => ':count سطر مساهمة مُرحّل|:count أسطر مساهمة مُرحّلة',
    ':count request pending review totaling :amount|:count requests pending review totaling :amount' => ':count طلب بانتظار المراجعة بإجمالي :amount|:count طلبات بانتظار المراجعة بإجمالي :amount',
    ':count unposted period across :members member(s)|:count unposted periods across :members member(s)' => ':count فترة غير مُرحّلة لـ :members عضو|:count فترات غير مُرحّلة لـ :members عضو',
    'Books are closed through :date. Backdated postings are not allowed.' => 'الدفاتر مُغلقة حتى :date. الترحيلات بأثر رجعي غير مسموحة.',
    'Master account invariant failed (MASTER_IMBALANCE). Fund delta: :fund_delta, Cash delta: :cash_delta' => 'فشل ثبات حساب الصندوق (MASTER_IMBALANCE). فرق الصندوق: :fund_delta، فرق النقد: :cash_delta',
    'Member fund balance (:balance) is insufficient for the member portion (:portion).' => 'رصيد صندوق العضو (:balance) غير كافٍ للحصة المخصصة للعضو (:portion).',
    'Member import is incomplete: :count CSV row(s) are not in the database (e.g. :sample). Reported created :created, skipped :skipped, failed :failed. Fix the CSV or clear partial imports, then run the migration again.' => 'استيراد الأعضاء غير مكتمل: :count صف/صفوف CSV غير موجودة في قاعدة البيانات (مثل :sample). المُبلّغ عنه: أُنشئ :created، تُخطّي :skipped، فشل :failed. صحّح ملف CSV أو امسح الاستيرادات الجزئية ثم أعد تشغيل الترحيل.',
    'Named capture group "amount". Thousands commas are stripped automatically.' => 'مجموعة التقاط مسماة «amount». تُزال فواصل الآلاف تلقائياً.',
    'Named capture group "date". Used when no date column is mapped.' => 'مجموعة التقاط مسماة «date». تُستخدم عند عدم تعيين عمود تاريخ.',
    'Named capture group "member".' => 'مجموعة التقاط مسماة «member».',
    'Named capture group "reference".' => 'مجموعة التقاط مسماة «reference».',
    'One row per active loan with amount_approved, disbursed_at, paid_installments_count, total_amount_repaid, and optional guarantor_member_number or guarantor_name. Identify the borrower with member_number or member_name.' => 'صف لكل قرض نشط يتضمن amount_approved وdisbursed_at وpaid_installments_count وtotal_amount_repaid وguarantor_member_number أو guarantor_name اختيارياً. حدّد المقترض بـ member_number أو member_name.',
    'Permanently deletes collected contributions, paid installments, closed fund postings, and audit log rows through the close period. Open arrears and pending installments are kept. This cannot be undone.' => 'يحذف نهائياً المساهمات المُحصّلة والأقساط المدفوعة وترحيلات الصندوق المغلقة وسطور سجل التدقيق حتى فترة الإغلاق. تُحفظ المتأخرات المفتوحة والأقساط المعلّقة. لا يمكن التراجع عن ذلك.',
    'Post-purge master pool invariant failed. Fund delta :fund, cash delta :cash.' => 'فشل ثبات مجمع الصندوق بعد التطهير. فرق الصندوق :fund، فرق النقد :cash.',
    'Post-purge member drift detected for :count member(s) after :label purge.' => 'رُصد انحراف عضو لـ :count عضو/أعضاء بعد تطهير :label.',
    'Review declared transfer details before approving. When the declared transfer is below the required subscription fee, approval is still allowed and the shortfall is flagged as subscription fee arrears. Subscription transfers are posted to member and master cash only (not the bank accounts module).' => 'راجع تفاصيل التحويل المُعلَنة قبل الموافقة. عندما يكون التحويل المُعلَن أقل من رسوم الاشتراك المطلوبة، تبقى الموافقة مسموحة ويُوسَم النقص كمتأخرات رسوم اشتراك. تُرحَّل تحويلات الاشتراك إلى نقد العضو ونقد الصندوق فقط (وليس وحدة الحسابات البنكية).',
    'This sends the same message (and attachments) to every member who has a login account. Currently: ' => 'يرسل الرسالة نفسها (والمرفقات) إلى كل عضو لديه حساب تسجيل دخول. حالياً: ',
    'yes' => 'نعم',
    'no' => 'لا',
    'No reconciliation snapshot found in the selected date range.' => 'لم يُعثر على لقطة مطابقة في نطاق التاريخ المحدد.',
    'PDF export is not available for audit trail exports. Choose CSV or Excel.' => 'تصدير PDF غير متاح لتصديرات سجل التدقيق. اختر CSV أو Excel.',
    'PDF export is not available for collections. Choose CSV or Excel.' => 'تصدير PDF غير متاح للتحصيلات. اختر CSV أو Excel.',
    'PDF export is not available for guarantor exposure. Choose CSV or Excel.' => 'تصدير PDF غير متاح لتقارير تعرض الكفيل. اختر CSV أو Excel.',
    'PDF export is not available for loan portfolio reports. Choose CSV or Excel.' => 'تصدير PDF غير متاح لتقارير محفظة القروض. اختر CSV أو Excel.',
    'The start date must be on or before the end date.' => 'يجب أن يكون تاريخ البداية في أو قبل تاريخ النهاية.',
    'Unknown report type: :type' => 'نوع تقرير غير معروف: :type',
    'Export guarantor exposure or open the delinquency guarantor tab.' => 'صدّر تعرض الكفيل أو افتح تبويب الكفلاء المتعثرين.',
    'Report export failed' => 'فشل تصدير التقرير',
    'YYYY-MM' => 'YYYY-MM',
    'English' => 'الإنجليزية',
    'Pipe (|)' => 'فاصل (|)',
    '50–89%' => '٥٠–٨٩٪',
    ':bank · :date' => 'البنك :bank · التاريخ :date',
    ':period — :status' => 'الفترة :period — :status',
    'Excel' => 'إكسل',
    'RECON_AUTO_FEE_EXEMPTION_REVERSAL' => 'إلغاء إعفاء الرسوم تلقائياً',
    'RECON_MANUAL_CORRECTION — :reason' => 'تصحيح يدوي — :reason',
];
