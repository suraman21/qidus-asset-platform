<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;

/**
 * Bilingual Amharic-first UI translation & language preference helper.
 * Default language is Amharic ('am'), switchable to English ('en').
 */
function qaLang(): string
{
    $sess = $_SESSION['qa_lang'] ?? null;
    if ($sess === 'am' || $sess === 'en') {
        return $sess;
    }
    $cookie = $_COOKIE['qa_lang'] ?? null;
    if ($cookie === 'am' || $cookie === 'en') {
        $_SESSION['qa_lang'] = $cookie;
        return $cookie;
    }
    $_SESSION['qa_lang'] = 'am';
    return 'am';
}

function qaSetLang(string $lang): void
{
    $clean = $lang === 'en' ? 'en' : 'am';
    $_SESSION['qa_lang'] = $clean;
    if (!headers_sent()) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('qa_lang', $clean, [
            'expires' => time() + 86400 * 180,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function qaLangSwitchUrl(string $targetLang): string
{
    $clean = $targetLang === 'en' ? 'en' : 'am';
    $params = $_GET;
    $params['set_lang'] = $clean;
    return 'index.php?' . http_build_query($params);
}

/**
 * Resolve school display title from qa_install_config for the active language.
 */
function qaSchoolTitle(): string
{
    static $cached = null;
    if ($cached === null) {
        $cached = [
            'en' => (string)qa_config('school_name_en', 'QIDUS ASSET'),
            'am' => (string)qa_config('school_name_am', 'ቅዱስ ንብረት'),
        ];
        try {
            $pdo = Connection::get();
            $row = $pdo->query('SELECT school_name_en, school_name_am FROM qa_install_config WHERE id = 1 LIMIT 1')->fetch();
            if ($row) {
                if (!empty($row['school_name_en'])) {
                    $cached['en'] = (string)$row['school_name_en'];
                }
                if (!empty($row['school_name_am'])) {
                    $cached['am'] = (string)$row['school_name_am'];
                }
            }
        } catch (\Throwable $e) {
            // Fallback to config defaults before migration
        }
    }
    return qaLang() === 'am' && $cached['am'] !== '' ? $cached['am'] : $cached['en'];
}

function qaT(string $key): string
{
    static $dict = [
        'brand.sub' => ['am' => 'እያንዳንዱ ብር፣ ንብረትና ሰነድ ተመዝግቦ ይጠበቃል።', 'en' => 'Every Birr, Asset & Document'],
        'nav.departments' => ['am' => 'ክፍሎች', 'en' => 'Departments'],
        'nav.executive' => ['am' => 'አመራር እና ኦዲት', 'en' => 'Executive & Audit'],
        'nav.asset' => ['am' => 'የንብረት ክፍል', 'en' => 'Asset Department'],
        'nav.finance' => ['am' => 'በጀት፣ ፋይናንስና ሰነድ', 'en' => 'Finance, Proc. & Docs'],
        'nav.account' => ['am' => 'የመለያ አስተዳደር', 'en' => 'Account'],
        'nav.profile' => ['am' => 'የግል መገለጫ', 'en' => 'My Profile'],
        'nav.signout' => ['am' => 'ውጣ', 'en' => 'Sign Out'],

        'badge.gov' => ['am' => 'አመራር', 'en' => 'Gov'],
        'badge.dept1' => ['am' => 'ክፍል 1', 'en' => 'Dept 1'],
        'badge.dept2' => ['am' => 'ክፍል 2', 'en' => 'Dept 2'],
        'badge.profile' => ['am' => 'መገለጫ', 'en' => 'Profile'],

        'topbar.mode' => ['am' => 'ሁነታ', 'en' => 'Mode'],
        'topbar.role' => ['am' => 'ሚና', 'en' => 'Role'],
        'topbar.view_as' => ['am' => 'እንደዚህ እይ:', 'en' => 'View as:'],
        'topbar.switch' => ['am' => 'ቀይር', 'en' => 'Switch'],
        'topbar.light_mode' => ['am' => '☀️ ብርሃን', 'en' => '☀️ Light Mode'],
        'topbar.dark_mode' => ['am' => '🌙 ጨለማ', 'en' => '🌙 Dark Mode'],
        'topbar.impersonating' => ['am' => 'የሚና መለዋወጫ በሥራ ላይ ነው፦ እንደ', 'en' => 'Impersonation Mode Active: Viewing platform as'],
        'topbar.return_admin' => ['am' => 'ወደ system_admin ተመለስ', 'en' => 'Return to system_admin'],

        'page.leadership' => ['am' => 'የአመራር አጠቃላይ እይታ እና ኦዲት', 'en' => 'Executive Overview & Audit Console'],
        'page.asset' => ['am' => 'የንብረት ክፍል (ክፍል 1)', 'en' => 'Asset Department'],
        'page.finance' => ['am' => 'በጀት፣ ፋይናንስ፣ ግዢ እና ሰነድ (ክፍል 2)', 'en' => 'Finance, Procurement & Document Vault'],
        'page.profile' => ['am' => 'የግል መገለጫ እና የይለፍ ቃል', 'en' => 'Profile & Security Settings'],

        'auth.sign_in' => ['am' => 'ግባ', 'en' => 'Sign In'],
        'auth.subtitle' => ['am' => 'የንብረት፣ ፋይናንስ፣ ግዢና ሰነድ አስተዳደር', 'en' => 'Asset, Finance, Procurement & Vault'],
        'auth.username' => ['am' => 'የተጠቃሚ ስም', 'en' => 'Username'],
        'auth.password' => ['am' => 'የይለፍ ቃል', 'en' => 'Password'],
        'auth.username_ph' => ['am' => 'የተጠቃሚ ስምዎን ያስገቡ', 'en' => 'Enter your username'],
        'auth.motto' => ['am' => '«እያንዳንዱ ብር፣ እያንዳንዱ ንብረትና እያንዳንዱ ሰነድ ተመዝግቦ ይጠበቃል።»', 'en' => '"Every Birr, Every Asset, Every Document — Accounted For."'],

        'setup.title' => ['am' => 'ቅዱስ ንብረት — የመጀመሪያ ማዋቀሪያ', 'en' => 'QIDUS ASSET — First-Run Setup'],
        'setup.subtitle' => ['am' => 'የሰንበት ትምህርት ቤቱን መረጃ እና የመጀመሪያውን አስተዳዳሪ ይፍጠሩ', 'en' => 'Configure school instance & initial System Admin'],
        'setup.school_en' => ['am' => 'የሰንበት ትምህርት ቤት ስም (በእንግሊዝኛ)', 'en' => 'Sunday School Name (English)'],
        'setup.school_am' => ['am' => 'የሰንበት ትምህርት ቤት ስም (በአማርኛ)', 'en' => 'Sunday School Name (Amharic)'],
        'setup.mode' => ['am' => 'የማገናኛ ሁነታ', 'en' => 'Deployment Integration Mode'],
        'setup.admin_user' => ['am' => 'የአስተዳዳሪ የተጠቃሚ ስም', 'en' => 'Initial Admin Username'],
        'setup.admin_name' => ['am' => 'የአስተዳዳሪ ሙሉ ስም', 'en' => 'Initial Admin Full Name'],
        'setup.admin_pass' => ['am' => 'የአስተዳዳሪ የይለፍ ቃል (ቢያንስ 8 ፊደላት)', 'en' => 'Initial Admin Password (min 8 chars)'],
        'setup.submit' => ['am' => 'ማዋቀሪያውን አጠናቅቅ', 'en' => 'Complete First-Run Setup'],

        'exec.kpi_asset_val' => ['am' => 'የንብረት ግምት (ክፍል 1)', 'en' => 'Asset Valuation (Dept 1)'],
        'exec.kpi_cash' => ['am' => 'የጥሬ ገንዘብ ሚዛን (ክፍል 2)', 'en' => 'Net Cash Position (Dept 2)'],
        'exec.kpi_budget' => ['am' => 'የበጀት አጠቃቀም', 'en' => 'Budget Utilization'],
        'exec.kpi_pending' => ['am' => 'ማጽደቅ የሚጠባበቁ', 'en' => 'Pending Approvals'],
        'exec.kpi_findings' => ['am' => 'ክፍት የኦዲት ግኝቶች', 'en' => 'Open Audit Findings'],
        'exec.kpi_health' => ['am' => 'የሲስተም ጤና', 'en' => 'System Health'],

        'exec.findings_title' => ['am' => 'የውስጥ ኦዲት ግኝቶች (FR-18)', 'en' => 'Internal Audit Findings (FR-18)'],
        'exec.members_years_title' => ['am' => 'የአባላት ማመሳሰያ እና የሥራ ዘመን', 'en' => 'Member Connector & Operational Years'],
        'exec.devices_title' => ['am' => 'የተመዘገቡ አንድሮይድ መሣሪያዎች (Keystore)', 'en' => 'Registered Android Devices (Keystore)'],
        'exec.staff_title' => ['am' => 'የሠራተኞች ማውጫ እና መለያ መፍጠሪያ', 'en' => 'Staff Directory & Provisioning'],
        'exec.audit_title' => ['am' => 'የማይቀየር የኦዲት መዝገብ እና CSV', 'en' => 'Immutable Audit Trail Explorer & CSV'],

        'profile.info_title' => ['am' => 'የግል መረጃ አስተዳደር', 'en' => 'Personal Profile Details'],
        'profile.username' => ['am' => 'የተጠቃሚ ስም', 'en' => 'Username'],
        'profile.full_name_en' => ['am' => 'ሙሉ ስም (በእንግሊዝኛ)', 'en' => 'Full Name (English)'],
        'profile.full_name_am' => ['am' => 'ሙሉ ስም (በአማርኛ)', 'en' => 'Full Name (Amharic)'],
        'profile.email' => ['am' => 'ኢሜይል', 'en' => 'Email Address'],
        'profile.language' => ['am' => 'የሚመርጡት ቋንቋ', 'en' => 'Preferred Interface Language'],
        'profile.save_btn' => ['am' => 'መገለጫ አስቀምጥ', 'en' => 'Save Profile'],
        'profile.pw_title' => ['am' => 'የይለፍ ቃል መቀየሪያ', 'en' => 'Change Password'],
        'profile.pw_current' => ['am' => 'የአሁኑ የይለፍ ቃል', 'en' => 'Current Password'],
        'profile.pw_new' => ['am' => 'አዲስ የይለፍ ቃል (ቢያንስ 10 ፊደላት)', 'en' => 'New Password (min 10 chars)'],
        'profile.pw_confirm' => ['am' => 'አዲሱን የይለፍ ቃል ያረጋግጡ', 'en' => 'Confirm New Password'],
        'profile.pw_btn' => ['am' => 'የይለፍ ቃል ቀይር', 'en' => 'Update Password'],
        'profile.meta_title' => ['am' => 'የመለያ ሁኔታ እና ደህንነት', 'en' => 'Account & Security Overview'],
        'profile.devices_title' => ['am' => 'የእኔ አንድሮይድ መሣሪያዎች', 'en' => 'My Registered Android Devices'],
    ];

    $lang = qaLang();
    if (isset($dict[$key][$lang])) {
        return $dict[$key][$lang];
    }
    return $dict[$key]['en'] ?? $key;
}
