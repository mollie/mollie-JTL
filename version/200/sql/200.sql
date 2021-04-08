CREATE TABLE IF NOT EXISTS `xplugin_ws_mollie_queue` (
    `kId`       int(11)     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `cType`     VARCHAR(32) NOT NULL,
    `cData`     TEXT             DEFAULT '',
    `cResult`   TEXT        NULL DEFAULT NULL,
    `dDone`     DATETIME    NULL DEFAULT NULL,
    `dCreated`  DATETIME    NOT NULL,
    `dModified` DATETIME    NULL DEFAULT NULL
);

ALTER TABLE `xplugin_ws_mollie_payments`
    ADD `dReminder` datetime NULL;
ALTER TABLE `xplugin_ws_mollie_payments`
    ADD `cTransactionId` VARCHAR(32) DEFAULT '';
ALTER TABLE `xplugin_ws_mollie_payments`
    ADD `bSynced` TINYINT(1) NOT NULL;

UPDATE `xplugin_ws_mollie_payments`
SET `bSynced` = true;