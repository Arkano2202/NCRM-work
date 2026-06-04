CREATE TABLE chat_grupo_vistos (
    id INT(11) NOT NULL AUTO_INCREMENT,
    grupo_tl_id INT(11) NOT NULL,
    usuario_id INT(11) NOT NULL,
    ultimo_mensaje_id INT(11) NOT NULL,
    visto_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_grupo_usuario (grupo_tl_id, usuario_id),
    KEY idx_usuario (usuario_id),
    KEY idx_grupo (grupo_tl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
