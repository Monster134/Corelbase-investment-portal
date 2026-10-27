-- =============================================================================
-- CORELBASE PRIVATE LIMITED — OPTIONAL DEMO DATA SEED (CLEARLY MARKED [DEMO])
-- WARNING: Do NOT import this file into a live production database with real
-- investor records. Use ONLY for staging/testing verification.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Sample Investor 1 (Completed workflow up to Certificate Generated — [DEMO])
INSERT INTO `investors` (
    `investor_id`, `full_name`, `email`, `mobile`, `dob`,
    `address`, `city`, `state`, `pincode`, `country`,
    `pan_encrypted`, `pan_masked`, `pan_hash`,
    `aadhaar_encrypted`, `aadhaar_masked`, `aadhaar_hash`,
    `kyc_status`, `is_duplicate_flagged`, `duplicate_notes`
) VALUES (
    'CB-2026-000001', 'Aarav Sharma [DEMO RECORD]', 'demo.investor1@example.com', '9876543210', '1992-05-14',
    '[DEMO] Plot 42, Gomti Nagar Extension', 'Lucknow', 'Uttar Pradesh', '226010', 'India',
    'DEMO_ENC_PAN_1', 'ABCDE****F', SHA2('ABCDE1234F', 256),
    'DEMO_ENC_AADHAAR_1', '********4821', SHA2('999988884821', 256),
    'VERIFIED', 0, 'Optional Demo Seed Record — Not a real investor'
) ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

SET @inv1_id = (SELECT `id` FROM `investors` WHERE `investor_id` = 'CB-2026-000001' LIMIT 1);

INSERT INTO `applications` (
    `application_id`, `investor_ref_id`, `investor_id`, `application_reference`,
    `investment_amount`, `indicative_shares`, `indicative_ownership_pct`,
    `configured_valuation`, `configured_issue_price`, `configured_face_value`, `configured_premium`,
    `status`, `admin_notes`, `verified_at`, `approved_at`
) VALUES (
    'CB-2026-000001', @inv1_id, 'CB-2026-000001', 'DEMO-REF-CB-2026-000001',
    25000.00, 25.0000, 0.025000,
    100000000.00, 1000.00, 10.00, 990.00,
    'CERTIFICATE GENERATED', '[DEMO RECORD] Verified and allotted for demonstration testing.', NOW(), NOW()
) ON DUPLICATE KEY UPDATE `status` = VALUES(`status`);

SET @app1_id = (SELECT `id` FROM `applications` WHERE `application_id` = 'CB-2026-000001' LIMIT 1);

INSERT INTO `payments` (
    `application_ref_id`, `application_id`, `investor_id`,
    `payment_method`, `payment_date`, `utr_number`, `amount_paid`,
    `sender_bank_or_upi`, `verification_status`, `verified_at`, `admin_remarks`
) VALUES (
    @app1_id, 'CB-2026-000001', 'CB-2026-000001',
    'Bank Transfer', '2026-10-25', 'DEMO426190001122', 25000.00,
    'HDFC Bank NEFT [DEMO]', 'VERIFIED', NOW(), '[DEMO] Verified against sample bank statement'
);

INSERT INTO `share_allocations` (
    `application_ref_id`, `application_id`, `investor_ref_id`, `investor_id`,
    `folio_number`, `share_class`, `shares_allotted`, `distinctive_from`, `distinctive_to`,
    `face_value`, `issue_price`, `premium_per_share`, `paid_up_value_per_share`,
    `total_allotment_amount`, `ownership_percentage`, `allotment_date`,
    `board_resolution_ref`, `allotment_notes`, `allotted_by_admin_id`
) VALUES (
    @app1_id, 'CB-2026-000001', @inv1_id, 'CB-2026-000001',
    'CB-FOLIO-2026-0001', 'Equity Shares', 25, 1001, 1025,
    10.00, 1000.00, 990.00, 10.00,
    25000.00, 0.025000, '2026-10-26',
    'DEMO-BR-2026-01', '[DEMO RECORD] Sample share allotment entry', 1
) ON DUPLICATE KEY UPDATE `shares_allotted` = VALUES(`shares_allotted`);

SET @alloc1_id = (SELECT `id` FROM `share_allocations` WHERE `application_id` = 'CB-2026-000001' LIMIT 1);

INSERT INTO `certificates` (
    `certificate_number`, `allocation_id`, `application_ref_id`, `application_id`,
    `investor_ref_id`, `investor_id`, `investor_name`, `folio_number`,
    `shares_count`, `share_class`, `face_value`, `paid_up_value`, `issue_price`,
    `ownership_percentage`, `allotment_date`, `verification_token`,
    `qr_verification_url`, `pdf_relative_path`, `status`, `issued_by`
) VALUES (
    'CB-SC-2026-000001', @alloc1_id, @app1_id, 'CB-2026-000001',
    @inv1_id, 'CB-2026-000001', 'Aarav Sharma [DEMO RECORD]', 'CB-FOLIO-2026-0001',
    25, 'Equity Shares', 10.00, 10.00, 1000.00,
    0.025000, '2026-10-26', 'DEMO-VERIFY-TOKEN-000001',
    'https://YOUR-DOMAIN/verify.php?id=CB-SC-2026-000001',
    'certificates/CB-SC-2026-000001.pdf', 'VALID', 'Corelbase Private Limited'
) ON DUPLICATE KEY UPDATE `status` = VALUES(`status`);

-- 2. Sample Investor 2 (Payment Under Verification — [DEMO])
INSERT INTO `investors` (
    `investor_id`, `full_name`, `email`, `mobile`, `dob`,
    `address`, `city`, `state`, `pincode`, `country`,
    `pan_encrypted`, `pan_masked`, `pan_hash`,
    `aadhaar_encrypted`, `aadhaar_masked`, `aadhaar_hash`,
    `kyc_status`, `is_duplicate_flagged`, `duplicate_notes`
) VALUES (
    'CB-2026-000002', 'Priya Verma [DEMO RECORD]', 'demo.investor2@example.com', '9811122233', '1995-08-22',
    '[DEMO] 19 Civil Lines', 'Gorakhpur', 'Uttar Pradesh', '273001', 'India',
    'DEMO_ENC_PAN_2', 'FGHIJ****K', SHA2('FGHIJ5678K', 256),
    'DEMO_ENC_AADHAAR_2', '********7390', SHA2('888877777390', 256),
    'PENDING', 0, 'Optional Demo Seed Record — Not a real investor'
) ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

SET @inv2_id = (SELECT `id` FROM `investors` WHERE `investor_id` = 'CB-2026-000002' LIMIT 1);

INSERT INTO `applications` (
    `application_id`, `investor_ref_id`, `investor_id`, `application_reference`,
    `investment_amount`, `indicative_shares`, `indicative_ownership_pct`,
    `configured_valuation`, `configured_issue_price`, `configured_face_value`, `configured_premium`,
    `status`, `admin_notes`
) VALUES (
    'CB-2026-000002', @inv2_id, 'CB-2026-000002', 'DEMO-REF-CB-2026-000002',
    50000.00, 50.0000, 0.050000,
    100000000.00, 1000.00, 10.00, 990.00,
    'PAYMENT UNDER VERIFICATION', '[DEMO RECORD] Pending payment verification by administrator.'
) ON DUPLICATE KEY UPDATE `status` = VALUES(`status`);

SET @app2_id = (SELECT `id` FROM `applications` WHERE `application_id` = 'CB-2026-000002' LIMIT 1);

INSERT INTO `payments` (
    `application_ref_id`, `application_id`, `investor_id`,
    `payment_method`, `payment_date`, `utr_number`, `amount_paid`,
    `sender_bank_or_upi`, `verification_status`, `admin_remarks`
) VALUES (
    @app2_id, 'CB-2026-000002', 'CB-2026-000002',
    'UPI', '2026-10-26', 'DEMO630198872341', 50000.00,
    'State Bank of India UPI [DEMO]', 'UNDER_VERIFICATION', '[DEMO] Awaiting admin verification'
);
