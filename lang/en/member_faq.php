<?php

declare(strict_types=1);

return [
    [
        'question' => 'When is my contribution collected?',
        'answer' => 'Your contribution is auto-debited from your cash account on Day 1 of the collection window each month. If your balance is insufficient, a partial debit is posted and the remainder is collected when funds arrive.',
    ],
    [
        'question' => 'Why am I exempt from contributions?',
        'answer' => 'Members with an active loan — including those in a grace period — are automatically exempt from the monthly contribution. This prevents double-charging. The exemption lifts in the cycle after your loan is fully repaid.',
    ],
    [
        'question' => 'How is my loan repayment threshold calculated?',
        'answer' => 'Your loan is fully repaid when the master fund portion plus 5% of the original loan amount has been repaid. You can see your specific threshold on the My Loans page.',
    ],
    [
        'question' => 'What happens if I miss EMI payments?',
        'answer' => 'Missed EMIs follow the same late fee tiers as contributions. After a configured number of consecutive missed EMIs, your guarantor is formally notified. After a further threshold, the loan is transferred to your guarantor and your account is suspended.',
    ],
    [
        'question' => 'How do I partially settle my loan?',
        'answer' => 'Go to My Loans → Settle loan. Enter a partial amount (minimum 1 EMI) and choose whether to roll up your schedule or skip upcoming cycles.',
    ],
    [
        'question' => 'How do I add funds to my cash account?',
        'answer' => 'Go to Cash Account and use the Deposit form. Bank transfers are credited after import matching (1–2 business days). Direct cash deposits are credited immediately.',
    ],
    [
        'question' => 'What is my guarantor responsible for?',
        'answer' => 'Your guarantor is responsible for the master fund portion of your loan plus the 5% settlement threshold, only if you miss the configured number of consecutive EMI payments.',
    ],
    [
        'question' => 'How do I request a cash out?',
        'answer' => 'Go to Cash Out. You can withdraw up to your available balance (cash balance minus reserved EMI). Withdrawals are processed to your registered IBAN within 1–2 business days.',
    ],
];
