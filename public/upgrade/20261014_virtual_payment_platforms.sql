-- Legacy enabled configurations continue supporting both operating systems.
ALTER TABLE `la_virtual_pay_config` ADD COLUMN `enabled_platforms` varchar(32) NOT NULL DEFAULT 'ios,android';
