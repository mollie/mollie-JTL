CREATE TABLE `xplugin_ws_mollie_payments`
(
  `kID`             VARCHAR(32)             NOT NULL,
  `kBestellung`     INT(11)                 NULL,
  `cMode`           VARCHAR(16)             NULL,
  `cStatus`         VARCHAR(16)             NOT NULL,
  `cHash`           VARCHAR(32)             NULL,
  `fAmount`         FLOAT                   NOT NULL,
  `cOrderNumber`    VARCHAR(32)  DEFAULT '' NOT NULL,
  `cCurrency`       VARCHAR(3)              NOT NULL,
  `cMethod`         VARCHAR(16)             NULL,
  `cLocale`         VARCHAR(5)              NOT NULL,
  `bCancelable`     INT(1)                  NOT NULL,
  `cWebhookURL`     VARCHAR(255) DEFAULT '' NOT NULL,
  `cRedirectURL`    VARCHAR(255) DEFAULT '' NOT NULL,
  `cCheckoutURL`    VARCHAR(255) DEFAULT '' NOT NULL,
  `fAmountCaptured` FLOAT                   NULL,
  `fAmountRefunded` FLOAT                   NULL,
  `dCreatedAt`      DATETIME                NULL
);

ALTER TABLE `xplugin_ws_mollie_payments`
  ADD PRIMARY KEY `kID` (`kID`);