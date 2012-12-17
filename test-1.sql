-- phpMyAdmin SQL Dump
-- version 3.4.10.1deb1
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Erstellungszeit: 17. Dez 2012 um 15:49
-- Server Version: 5.5.28
-- PHP-Version: 5.3.10-1ubuntu3.4

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Datenbank: `test`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `testtab1`
--

CREATE TABLE IF NOT EXISTS `testtab1` (
  `id1` int(11) NOT NULL,
  `text1` varchar(100) NOT NULL,
  `foreign1` int(11) NOT NULL,
  `constintern` int(11) NOT NULL,
  `foreign2` int(11) NOT NULL,
  `uniqindex` int(11) NOT NULL,
  `foreign4a` int(11) NOT NULL,
  `foreign4b` int(11) NOT NULL,
  PRIMARY KEY (`id1`),
  UNIQUE KEY `uniqindex` (`uniqindex`),
  KEY `foreign1` (`foreign1`),
  KEY `constintern` (`constintern`),
  KEY `foreign2` (`foreign2`),
  KEY `foreign4` (`foreign4a`,`foreign4b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `testtab2`
--

CREATE TABLE IF NOT EXISTS `testtab2` (
  `id1` int(11) NOT NULL,
  `text` varchar(500) NOT NULL,
  `foreign2` int(11) NOT NULL,
  PRIMARY KEY (`id1`),
  KEY `foreign2` (`foreign2`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `testtab3`
--

CREATE TABLE IF NOT EXISTS `testtab3` (
  `uid` int(11) NOT NULL,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `testtab4`
--

CREATE TABLE IF NOT EXISTS `testtab4` (
  `id1` int(11) NOT NULL,
  `id2` int(11) NOT NULL,
  `text` varchar(500) NOT NULL,
  PRIMARY KEY (`id1`),
  UNIQUE KEY `twofieldunique` (`id1`,`id2`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `testtab14`
--

CREATE TABLE IF NOT EXISTS `testtab14` (
  `id1` int(11) NOT NULL,
  `text1` varchar(100) NOT NULL,
  `foreign1` int(11) NOT NULL,
  `constintern` int(11) NOT NULL,
  `foreign2` int(11) NOT NULL,
  `uniqindex` int(11) NOT NULL,
  `foreign4a` int(11) NOT NULL,
  `foreign4b` int(11) NOT NULL,
  PRIMARY KEY (`id1`),
  UNIQUE KEY `uniqindex` (`uniqindex`),
  KEY `foreign1` (`foreign1`),
  KEY `constintern` (`constintern`),
  KEY `foreign2` (`foreign2`),
  KEY `foreign4` (`foreign4a`,`foreign4b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `testtab1`
--
ALTER TABLE `testtab1`
  ADD CONSTRAINT `testtab1_ibfk_1` FOREIGN KEY (`foreign1`) REFERENCES `testtab2` (`id1`),
  ADD CONSTRAINT `testtab1_ibfk_2` FOREIGN KEY (`foreign2`) REFERENCES `testtab3` (`uid`);

--
-- Constraints der Tabelle `testtab2`
--
ALTER TABLE `testtab2`
  ADD CONSTRAINT `testtab2_ibfk_1` FOREIGN KEY (`foreign2`) REFERENCES `testtab1` (`id1`);

--
-- Constraints der Tabelle `testtab4`
--
ALTER TABLE `testtab4`
  ADD CONSTRAINT `testtab4_ibfk_1` FOREIGN KEY (`id1`) REFERENCES `testtab1` (`id1`);

--
-- Constraints der Tabelle `testtab14`
--
ALTER TABLE `testtab14`
  ADD CONSTRAINT `testtab14_myfk2` FOREIGN KEY (`foreign4a`, `foreign4b`) REFERENCES `testtab4` (`id1`, `id2`);

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
