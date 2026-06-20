-- josradesistimiento: tabla principal de solicitudes
-- Compatible MySQL 5.6+ / MariaDB 10.2+

CREATE TABLE IF NOT EXISTS `PREFIX_josra_desistimiento` (
    `id_desistimiento`  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order`          INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `id_customer`       INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `reference`         VARCHAR(64)  NOT NULL DEFAULT '',
    `nombre`            VARCHAR(150) NOT NULL DEFAULT '',
    `email`             VARCHAR(255) NOT NULL DEFAULT '',
    `motivo`            VARCHAR(20)  NOT NULL DEFAULT '',
    `comentario`        TEXT,
    `estado`            VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
    `opcion_retencion`  VARCHAR(20)  NOT NULL DEFAULT '',
    `ip`                VARCHAR(45)  NOT NULL DEFAULT '',
    `fecha_solicitud`   DATETIME     NOT NULL,
    `fecha_procesado`   DATETIME,
    `token`             VARCHAR(64)  NOT NULL DEFAULT '',
    PRIMARY KEY (`id_desistimiento`),
    KEY `idx_reference`   (`reference`),
    KEY `idx_customer`    (`id_customer`),
    KEY `idx_estado`      (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
