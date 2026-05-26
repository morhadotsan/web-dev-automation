-- Sample rows for exercising a generated PHP project.
-- Columns match the real reverbtime schema (see reverbtime.sql).
-- {{WEB_SLUG}} is replaced by tests/run_tests.php with site.website_slug from features.yaml.

SET NAMES utf8mb4;

-- Admin row used as the publisher of the seeded blog posts.
-- The whitelisted-author lookup in single-author.php maps a slug to this row via `company_url`.
INSERT INTO admin (admin_id, admin_email, admin_password, company_fullname, company_url, company_title, company_tagline, company_address, company_address_2, company_email_1, company_email_2, company_phone_1, company_phone_2) VALUES
(1, 'editor@example.com', 'unused', 'Test Magazine, Inc.', 'editor-in-chief', 'Editor in Chief', 'Test tagline.', '123 Test St', '', 'editor@example.com', '', '0000000000', '');

-- Authors. The author table is NOT tenant-filtered (no my_web_url column).
INSERT INTO author (author_slug, author_email, author_password, author_name, author_phone, login_type, token, author_img, trusted_author, account_status, register_date) VALUES
('jane-doe',   'jane@example.com', 'unused', 'Jane Doe',   '', 'manual', '', '', 'yes', 'active', '2026-04-01 00:00:00'),
('john-smith', 'john@example.com', 'unused', 'John Smith', '', 'manual', '', '', 'yes', 'active', '2026-04-01 00:00:00');

-- Three blog rows tenanted by {{WEB_SLUG}}, plus one foreign-tenant row used by the
-- multi-tenant leakage check (must NOT appear in any test page output).
INSERT INTO blog (admin_id, blog_title, blog_h1, url_slug, my_web_url, blog_author, blog_category, blog_image, blog_shortDesc, blog_keywords, blog_content, blog_date) VALUES
(1, 'Sample Business Article',   'Sample Business Article',   'sample-business-article',   '{{WEB_SLUG}}',     'jane-doe',     'business',   'blank-blog-image.jpg', 'A short business teaser used by the test fixture.',   'business, sample',   '<p>Sample business article body for the test fixture. Has enough words to compute a reading time.</p>', NOW()),
(1, 'Sample Technology Article', 'Sample Technology Article', 'sample-technology-article', '{{WEB_SLUG}}',     'jane-doe',     'technology', 'blank-blog-image.jpg', 'A short technology teaser used by the test fixture.', 'technology, sample', '<p>Sample technology article body for the test fixture. Has enough words to compute a reading time.</p>', NOW()),
(1, 'Sample Lifestyle Article',  'Sample Lifestyle Article',  'sample-lifestyle-article',  '{{WEB_SLUG}}',     'john-smith',   'lifestyle',  'blank-blog-image.jpg', 'A short lifestyle teaser used by the test fixture.',  'lifestyle, sample',  '<p>Sample lifestyle article body for the test fixture. Has enough words to compute a reading time.</p>', NOW()),
(1, 'Other-Tenant Article', 'Other-Tenant Article', 'other-tenant-article', '__other_tenant__', 'someone-else', 'business', 'blank-blog-image.jpg', 'Belongs to a different tenant — must NOT appear.',    'other, tenant',      '<p>If this row shows up in any test page output, the multi-tenant filter is broken.</p>', NOW());

-- A pre-existing comment so the comment listing on the single-article page has something to render.
INSERT INTO blog_comments (blog_url, user_key, comment_name, comment_email, comment, comment_date) VALUES
('sample-business-article', 'seed-session-key-1', 'Existing Commenter', 'commenter@example.com', 'A pre-existing comment so the comment listing has something to render.', '2026-04-11 10:00:00');