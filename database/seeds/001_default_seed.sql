-- ============================================================================
-- QIDUS ASSET Default Seed Data (Safe, Idempotent Configuration Defaults)
-- Note: Contains NO hardcoded production passwords or secret tokens.
-- ============================================================================

INSERT IGNORE INTO `qa_install_config`
    (`id`, `school_name_en`, `school_name_am`, `school_short_name`, `integration_mode`, `calendar_mode`, `currency_code`, `asset_code_prefix`)
VALUES
    (1, 'Sunday School', 'ሰንበት ትምህርት ቤት', 'QIDUS', 'standalone', 'dual', 'ETB', 'AST');

INSERT IGNORE INTO `qa_departments` (`code`, `name_en`, `name_am`, `is_active`) VALUES
    ('asset_dept', 'Asset Department', 'የንብረት ክፍል', 1),
    ('finance_dept', 'Finance & Related Department', 'የበጀት፣ የፋይናንስ፣ የሰነድና የግዢ ክፍል', 1);

INSERT IGNORE INTO `qa_operational_years` (`ec_year`, `label_en`, `label_am`, `start_date`, `end_date`, `status`) VALUES
    (2019, '2019 E.C. (2026/27)', '2019 ዓ.ም.', '2026-09-11', '2027-09-10', 'active');

INSERT IGNORE INTO `qa_asset_categories` (`code`, `name_en`, `name_am`, `tracking_mode`, `qr_required`, `requires_return`) VALUES
    ('LITURGICAL', 'Liturgical & Church Items', 'ንዋየ ቅድሳት', 'fixed', 1, 1),
    ('ELECTRONICS', 'Audio, Video & Electronics', 'ኤሌክትሮኒክስና የድምፅ መሳሪያዎች', 'fixed', 1, 1),
    ('FURNITURE', 'Furniture & Fixtures', 'የቢሮና የክፍል ዕቃዎች', 'fixed', 1, 0),
    ('UNIFORMS', 'Clothing & Choir Vestments', 'አልባሳትና ልብሰ ተክህኖ', 'reusable_member', 0, 1),
    ('BOOKS', 'Books & Teaching Aids', 'መጻሕፍትና የትምህርት መርጃዎች', 'reusable_member', 0, 1),
    ('SUPPLIES', 'Office & Consumable Supplies', 'የቢሮና አላቂ ዕቃዎች', 'consumable', 0, 0);

INSERT IGNORE INTO `qa_asset_locations` (`code`, `name_en`, `name_am`, `is_active`) VALUES
    ('MAIN_STORE', 'Main Store Room', 'ዋና ንብረት መጋዘን', 1),
    ('OFFICE', 'Administration Office', 'አስተዳደር ቢሮ', 1),
    ('HALL', 'Main Hall', 'ዋና አዳራሽ', 1);

INSERT IGNORE INTO `qa_fin_categories` (`code`, `name_en`, `name_am`, `type`) VALUES
    ('INC_CONTRIB', 'Monthly Member Contributions', 'ወርሃዊ የአባላት መዋጮ', 'income'),
    ('INC_OFFERING', 'Special Offerings & Tithes', 'ልዩ ስጦታና አሥራት', 'income'),
    ('INC_DONATION', 'General Donations', 'የበጎ አድራጎት ድጋፍ', 'income'),
    ('INC_EVENT', 'Event & Holiday Income', 'የበዓላትና ዝግጅቶች ገቢ', 'income'),
    ('EXP_TEACHING', 'Teaching Materials & Books', 'የትምህርት ቁሳቁስ', 'expense'),
    ('EXP_ASSET', 'Asset & Equipment Purchases', 'የንብረትና መሳሪያ ግዢ', 'expense'),
    ('EXP_MAINT', 'Maintenance & Repairs', 'ጥገናና እድሳት', 'expense'),
    ('EXP_EVENT', 'Events & Celebrations', 'የበዓላትና ዝግጅቶች ወጪ', 'expense'),
    ('EXP_OFFICE', 'Office & Administrative Supplies', 'የቢሮ ወጪዎች', 'expense'),
    ('EXP_UTIL', 'Utilities & Transport', 'መብራት፣ ውሃና ትራንስፖርት', 'expense');
