CREATE TABLE chat_grupo_mensajes (
    id INT(11) NOT NULL AUTO_INCREMENT,
    grupo_tl_id INT(11) NOT NULL,
    emisor_id INT(11) NOT NULL,
    mensaje TEXT NOT NULL,
    enviado_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_grupo_fecha (grupo_tl_id, enviado_en),
    KEY idx_emisor (emisor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
