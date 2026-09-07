CREATE TABLE {forms} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_key varchar(191) NOT NULL,
provider varchar(32) NOT NULL DEFAULT 'html',
provider_form_id varchar(191) NOT NULL DEFAULT '',
title varchar(255) NOT NULL DEFAULT '',
page_path varchar(500) NOT NULL DEFAULT '',
first_seen datetime NOT NULL,
last_seen datetime NOT NULL,
last_success_at datetime DEFAULT NULL,
last_failure_at datetime DEFAULT NULL,
last_mail_success_at datetime DEFAULT NULL,
last_mail_failure_at datetime DEFAULT NULL,
last_failure_code varchar(64) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
UNIQUE KEY form_key (form_key),
KEY provider_form (provider, provider_form_id),
KEY last_seen (last_seen)
		) {charset_collate};

CREATE TABLE {daily} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_id bigint(20) unsigned NOT NULL,
stat_date date NOT NULL,
views bigint(20) unsigned NOT NULL DEFAULT 0,
starts bigint(20) unsigned NOT NULL DEFAULT 0,
submit_attempts bigint(20) unsigned NOT NULL DEFAULT 0,
submissions bigint(20) unsigned NOT NULL DEFAULT 0,
confirmed_successes bigint(20) unsigned NOT NULL DEFAULT 0,
abandons bigint(20) unsigned NOT NULL DEFAULT 0,
validation_failures bigint(20) unsigned NOT NULL DEFAULT 0,
failures bigint(20) unsigned NOT NULL DEFAULT 0,
mail_successes bigint(20) unsigned NOT NULL DEFAULT 0,
mail_failures bigint(20) unsigned NOT NULL DEFAULT 0,
duration_total_ms bigint(20) unsigned NOT NULL DEFAULT 0,
duration_samples bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY form_date (form_id, stat_date),
KEY stat_date (stat_date)
		) {charset_collate};

CREATE TABLE {fields} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_id bigint(20) unsigned NOT NULL,
stat_date date NOT NULL,
field_key varchar(191) NOT NULL,
field_label varchar(191) NOT NULL DEFAULT '',
field_type varchar(32) NOT NULL DEFAULT '',
interactions bigint(20) unsigned NOT NULL DEFAULT 0,
abandonments bigint(20) unsigned NOT NULL DEFAULT 0,
validation_errors bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY form_date_field (form_id, stat_date, field_key),
KEY stat_date (stat_date)
		) {charset_collate};

CREATE TABLE {placements} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_id bigint(20) unsigned NOT NULL,
placement_key varchar(191) NOT NULL,
page_path varchar(500) NOT NULL DEFAULT '',
first_seen datetime NOT NULL,
last_seen datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY placement_key (placement_key),
KEY form_id (form_id),
KEY last_seen (last_seen)
		) {charset_collate};

CREATE TABLE {placement_daily} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
placement_id bigint(20) unsigned NOT NULL,
stat_date date NOT NULL,
views bigint(20) unsigned NOT NULL DEFAULT 0,
starts bigint(20) unsigned NOT NULL DEFAULT 0,
submit_attempts bigint(20) unsigned NOT NULL DEFAULT 0,
submissions bigint(20) unsigned NOT NULL DEFAULT 0,
confirmed_successes bigint(20) unsigned NOT NULL DEFAULT 0,
abandons bigint(20) unsigned NOT NULL DEFAULT 0,
validation_failures bigint(20) unsigned NOT NULL DEFAULT 0,
failures bigint(20) unsigned NOT NULL DEFAULT 0,
mail_successes bigint(20) unsigned NOT NULL DEFAULT 0,
mail_failures bigint(20) unsigned NOT NULL DEFAULT 0,
duration_total_ms bigint(20) unsigned NOT NULL DEFAULT 0,
duration_samples bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY placement_date (placement_id, stat_date),
KEY stat_date (stat_date)
		) {charset_collate};
