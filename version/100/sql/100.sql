CREATE TABLE `xplugin_ws_mollie_payments` (
  `kID` varchar(32) NOT NULL,
  `kBestellung` INT(11) NULL,
  `cStatus` varchar(16) NOT NULL,
  `cHash` varchar(32) NULL,
  `fAmount` float NOT NULL,
  `cCurrency` varchar(3) NOT NULL,
  `cMethod` varchar(16) NULL,
  `dCreatedAt` datetime NULL,
  `dPaidAt` datetime NULL
);

ALTER TABLE `xplugin_ws_mollie_payments`
ADD PRIMARY KEY `kID` (`kID`);