<?php

function chatNowBogota(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
}

function chatNowBogotaString(): string
{
    return chatNowBogota()->format('Y-m-d H:i:s');
}

function chatSupportsImages(mysqli $conn): bool
{
    static $supportsImages = null;

    if ($supportsImages !== null) {
        return $supportsImages;
    }

    $result = $conn->query("SHOW TABLES LIKE 'chat_imagenes_temp'");
    $supportsImages = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $supportsImages;
}

function chatImageColumnExists(mysqli $conn, string $column): bool
{
    static $columnMap = [];

    if (array_key_exists($column, $columnMap)) {
        return $columnMap[$column];
    }

    if (!chatSupportsImages($conn)) {
        $columnMap[$column] = false;
        return false;
    }

    $result = $conn->query("SHOW COLUMNS FROM chat_imagenes_temp LIKE '" . $conn->real_escape_string($column) . "'");
    $columnMap[$column] = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $columnMap[$column];
}

function chatSupportsGroupImages(mysqli $conn): bool
{
    static $supportsGroupImages = null;

    if ($supportsGroupImages !== null) {
        return $supportsGroupImages;
    }

    $requiredColumns = ['grupo_tl_id', 'grupo_mensaje_id'];
    foreach ($requiredColumns as $column) {
        if (!chatImageColumnExists($conn, $column)) {
            $supportsGroupImages = false;
            return false;
        }
    }

    $supportsGroupImages = true;
    return true;
}

function chatSupportsGroupChats(mysqli $conn): bool
{
    static $supportsGroupChats = null;

    if ($supportsGroupChats !== null) {
        return $supportsGroupChats;
    }

    $result = $conn->query("SHOW TABLES LIKE 'chat_grupo_mensajes'");
    $supportsGroupChats = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $supportsGroupChats;
}

function chatSupportsGroupReadState(mysqli $conn): bool
{
    static $supportsGroupReadState = null;

    if ($supportsGroupReadState !== null) {
        return $supportsGroupReadState;
    }

    $result = $conn->query("SHOW TABLES LIKE 'chat_grupo_vistos'");
    $supportsGroupReadState = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $supportsGroupReadState;
}

function chatSupportsReactions(mysqli $conn): bool
{
    static $supportsReactions = null;

    if ($supportsReactions !== null) {
        return $supportsReactions;
    }

    $result = $conn->query("SHOW TABLES LIKE 'chat_reacciones'");
    $supportsReactions = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $supportsReactions;
}

function chatSupportsDirectReplies(mysqli $conn): bool
{
    static $supportsDirectReplies = null;

    if ($supportsDirectReplies !== null) {
        return $supportsDirectReplies;
    }

    $requiredColumns = ['responde_a_tipo', 'responde_a_id'];
    $found = [];
    foreach ($requiredColumns as $column) {
        $result = $conn->query("SHOW COLUMNS FROM chat_mensajes LIKE '" . $conn->real_escape_string($column) . "'");
        $found[$column] = $result instanceof mysqli_result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    }

    $supportsDirectReplies = !in_array(false, $found, true);
    return $supportsDirectReplies;
}

function chatMessageColumnExists(mysqli $conn, string $column): bool
{
    static $columnMap = [];

    if (array_key_exists($column, $columnMap)) {
        return $columnMap[$column];
    }

    $result = $conn->query("SHOW COLUMNS FROM chat_mensajes LIKE '" . $conn->real_escape_string($column) . "'");
    $columnMap[$column] = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $columnMap[$column];
}

function chatSupportsDirectMessageEditState(mysqli $conn): bool
{
    return chatMessageColumnExists($conn, 'editado_en');
}

function chatSupportsDirectMessageDeleteState(mysqli $conn): bool
{
    return chatMessageColumnExists($conn, 'eliminado_en');
}

function chatIsPdfAttachment(?string $mimeType, ?string $originalName = null, ?string $storedFileName = null): bool
{
    $mimeType = strtolower(trim((string) $mimeType));
    $originalName = strtolower(trim((string) $originalName));
    $storedFileName = strtolower(trim((string) $storedFileName));

    if ($mimeType !== '' && str_contains($mimeType, 'pdf')) {
        return true;
    }

    return str_ends_with($originalName, '.pdf') || str_ends_with($storedFileName, '.pdf');
}

function chatReactionCatalog(): array
{
    return ['👍', '❤️', '😂', '😮', '😢', '👏', '🔥', '🙏', '👀', '✅', '🐽'];
}

function chatAllowedReactionCatalog(array $currentUser): array
{
    $catalog = chatReactionCatalog();
    if ((int) ($currentUser['Tipo'] ?? 0) === 1) {
        array_unshift($catalog, '🤌');
        array_unshift($catalog, '🦍');
    }

    return $catalog;
}

function chatImagesDiskPath(): string
{
    return dirname(__DIR__) . '/uploads/chat_temp';
}

function chatImagesPublicRelativePath(): string
{
    return 'uploads/chat_temp';
}

function chatUploadsRootPath(): string
{
    return dirname(__DIR__) . '/uploads';
}

function chatImageAbsolutePathFromFileName(string $fileName): string
{
    $safeName = basename(str_replace('\\', '/', $fileName));
    return rtrim(chatImagesDiskPath(), '/\\') . DIRECTORY_SEPARATOR . $safeName;
}

function chatEnsureImagesDirectory(): string
{
    $path = chatImagesDiskPath();
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    return $path;
}

function chatResolveAdminImagePath(string $fileName): ?string
{
    $safeName = basename(str_replace('\\', '/', trim($fileName)));
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        return null;
    }

    $fullPath = chatImageAbsolutePathFromFileName($safeName);
    if (!is_file($fullPath)) {
        return null;
    }

    return $fullPath;
}

function chatResolveAdminUploadPath(string $fileName): ?string
{
    $safeName = basename(str_replace('\\', '/', trim($fileName)));
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        return null;
    }

    $fullPath = rtrim(chatUploadsRootPath(), '/\\') . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($fullPath)) {
        return null;
    }

    return $fullPath;
}

function chatListAdminImages(mysqli $conn): array
{
    $directory = chatEnsureImagesDirectory();
    $dbImages = [];

    if (chatSupportsImages($conn)) {
        $sql = "
            SELECT
                i.id,
                i.nombre_original,
                i.nombre_archivo,
                i.ruta_relativa,
                i.mime_type,
                i.tamano_bytes,
                i.creado_en,
                e.Nombre AS emisor_nombre,
                r.Nombre AS receptor_nombre
            FROM chat_imagenes_temp i
            LEFT JOIN users e ON e.id = i.emisor_id
            LEFT JOIN users r ON r.id = i.receptor_id
        ";
        $result = $conn->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $fileKey = basename((string) ($row['nombre_archivo'] ?: $row['ruta_relativa'] ?? ''));
                if ($fileKey === '') {
                    continue;
                }

                $dbImages[$fileKey] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'original_name' => trim((string) ($row['nombre_original'] ?? '')),
                    'file_name' => $fileKey,
                    'relative_path' => trim((string) ($row['ruta_relativa'] ?? '')),
                    'mime_type' => trim((string) ($row['mime_type'] ?? 'application/octet-stream')),
                    'size_bytes' => (int) ($row['tamano_bytes'] ?? 0),
                    'created_at' => trim((string) ($row['creado_en'] ?? '')),
                    'sender_name' => trim((string) ($row['emisor_nombre'] ?? '')),
                    'receiver_name' => trim((string) ($row['receptor_nombre'] ?? '')),
                ];
            }
            $result->free();
        }
    }

    $items = [];
    $files = @scandir($directory) ?: [];
    foreach ($files as $fileName) {
        if ($fileName === '.' || $fileName === '..') {
            continue;
        }

        $fullPath = $directory . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($fullPath)) {
            continue;
        }

        $dbRow = $dbImages[$fileName] ?? null;
        $fileMTime = @filemtime($fullPath) ?: 0;
        $createdAt = $dbRow['created_at'] ?? ($fileMTime > 0 ? date('Y-m-d H:i:s', $fileMTime) : '');
        $items[] = [
            'file_name' => $fileName,
            'original_name' => $dbRow['original_name'] ?? $fileName,
            'mime_type' => $dbRow['mime_type'] ?? ((string) (@mime_content_type($fullPath) ?: 'application/octet-stream')),
            'size_bytes' => (int) ($dbRow['size_bytes'] ?? (@filesize($fullPath) ?: 0)),
            'created_at' => $createdAt,
            'created_at_ts' => strtotime($createdAt) ?: $fileMTime,
            'sender_name' => $dbRow['sender_name'] ?? '',
            'receiver_name' => $dbRow['receiver_name'] ?? '',
            'relative_path' => $dbRow['relative_path'] ?? (chatImagesPublicRelativePath() . '/' . rawurlencode($fileName)),
            'is_orphan' => $dbRow === null,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return (int) ($b['created_at_ts'] ?? 0) <=> (int) ($a['created_at_ts'] ?? 0);
    });

    return $items;
}

function chatFindAdminImage(mysqli $conn, string $fileName): ?array
{
    $safeName = basename(str_replace('\\', '/', trim($fileName)));
    if ($safeName === '') {
        return null;
    }

    foreach (chatListAdminImages($conn) as $image) {
        if (($image['file_name'] ?? '') === $safeName) {
            return $image;
        }
    }

    return null;
}

function chatDeleteAdminImage(mysqli $conn, string $fileName): bool
{
    $safeName = basename(str_replace('\\', '/', trim($fileName)));
    if ($safeName === '') {
        return false;
    }

    $deletedSomething = false;
    if (chatSupportsImages($conn)) {
        $likePath = '%/' . $safeName;
        $stmt = $conn->prepare("
            DELETE FROM chat_imagenes_temp
            WHERE nombre_archivo = ? OR ruta_relativa LIKE ?
        ");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('ss', $safeName, $likePath);
            $stmt->execute();
            $deletedSomething = $stmt->affected_rows > 0;
            $stmt->close();
        }
    }

    $fullPath = chatResolveAdminImagePath($safeName);
    if ($fullPath !== null && @unlink($fullPath)) {
        $deletedSomething = true;
    }

    return $deletedSomething;
}

function chatDeleteAllAdminImages(mysqli $conn): int
{
    $deletedCount = 0;
    foreach (chatListAdminImages($conn) as $image) {
        $fileName = (string) ($image['file_name'] ?? '');
        if ($fileName !== '' && chatDeleteAdminImage($conn, $fileName)) {
            $deletedCount++;
        }
    }

    return $deletedCount;
}

function chatListAdminUploadFiles(): array
{
    $directory = chatUploadsRootPath();
    if (!is_dir($directory)) {
        return [];
    }

    $items = [];
    $files = @scandir($directory) ?: [];
    foreach ($files as $fileName) {
        if ($fileName === '.' || $fileName === '..') {
            continue;
        }

        $fullPath = $directory . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($fullPath)) {
            continue;
        }

        $mtime = @filemtime($fullPath) ?: 0;
        $items[] = [
            'file_name' => $fileName,
            'mime_type' => (string) (@mime_content_type($fullPath) ?: 'application/octet-stream'),
            'size_bytes' => (int) (@filesize($fullPath) ?: 0),
            'created_at' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '',
            'created_at_ts' => $mtime,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return (int) ($b['created_at_ts'] ?? 0) <=> (int) ($a['created_at_ts'] ?? 0);
    });

    return $items;
}

function chatFindAdminUploadFile(string $fileName): ?array
{
    $safeName = basename(str_replace('\\', '/', trim($fileName)));
    if ($safeName === '') {
        return null;
    }

    foreach (chatListAdminUploadFiles() as $item) {
        if (($item['file_name'] ?? '') === $safeName) {
            return $item;
        }
    }

    return null;
}

function chatDeleteAdminUploadFile(string $fileName): bool
{
    $fullPath = chatResolveAdminUploadPath($fileName);
    if ($fullPath === null) {
        return false;
    }

    return @unlink($fullPath);
}

function chatDeleteAllAdminUploadFiles(): int
{
    $deletedCount = 0;
    foreach (chatListAdminUploadFiles() as $item) {
        $fileName = (string) ($item['file_name'] ?? '');
        if ($fileName !== '' && chatDeleteAdminUploadFile($fileName)) {
            $deletedCount++;
        }
    }

    return $deletedCount;
}

function chatReactionDbMessageId(string $kind, int $messageId): int
{
    return $kind === 'image' ? -abs($messageId) : abs($messageId);
}

function chatGetReactionSummaries(mysqli $conn, string $messageType, array $messageIds, int $currentUserId): array
{
    if (!chatSupportsReactions($conn) || empty($messageIds)) {
        return [];
    }

    $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
    if (empty($messageIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $types = 's' . str_repeat('i', count($messageIds));
    $params = array_merge([$messageType], $messageIds);
    $sql = "
        SELECT r.mensaje_id, r.emoji, COUNT(*) AS total,
               SUM(CASE WHEN r.usuario_id = ? THEN 1 ELSE 0 END) AS mine,
               GROUP_CONCAT(u.Nombre ORDER BY u.Nombre SEPARATOR ', ') AS reacted_by
        FROM chat_reacciones r
        INNER JOIN users u ON u.id = r.usuario_id
        WHERE r.tipo_mensaje = ? AND r.mensaje_id IN ($placeholders)
        GROUP BY r.mensaje_id, r.emoji
        ORDER BY r.mensaje_id ASC, total DESC, r.emoji ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $types = 'i' . $types;
    $params = array_merge([$currentUserId], $params);
    chatBindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $map = [];
    while ($row = $result->fetch_assoc()) {
        $messageId = (int) ($row['mensaje_id'] ?? 0);
        if (!isset($map[$messageId])) {
            $map[$messageId] = [];
        }
        $map[$messageId][] = [
            'emoji' => (string) ($row['emoji'] ?? ''),
            'count' => (int) ($row['total'] ?? 0),
            'mine' => (int) ($row['mine'] ?? 0) > 0,
            'reacted_by' => trim((string) ($row['reacted_by'] ?? '')),
        ];
    }
    $stmt->close();

    return $map;
}

function chatAttachReactionsToMessages(mysqli $conn, array $messages, string $messageType, int $currentUserId): array
{
    if (empty($messages)) {
        return $messages;
    }

    $ids = [];
    foreach ($messages as $message) {
        $ids[] = chatReactionDbMessageId((string) ($message['kind'] ?? 'text'), (int) ($message['id'] ?? 0));
    }

    $reactionMap = chatGetReactionSummaries($conn, $messageType, $ids, $currentUserId);
    foreach ($messages as &$message) {
        $dbMessageId = chatReactionDbMessageId((string) ($message['kind'] ?? 'text'), (int) ($message['id'] ?? 0));
        $message['reaction_type'] = $messageType;
        $message['reaction_message_id'] = $dbMessageId;
        $message['reactions'] = $reactionMap[$dbMessageId] ?? [];
    }
    unset($message);

    return $messages;
}

function chatToggleReaction(mysqli $conn, array $currentUser, string $messageType, int $messageId, string $emoji): array
{
    if (!chatSupportsReactions($conn)) {
        throw new RuntimeException('reactions_not_enabled');
    }

    $emoji = trim($emoji);
    if (!in_array($emoji, chatAllowedReactionCatalog($currentUser), true)) {
        throw new RuntimeException('reaction_not_allowed');
    }

    $userId = (int) ($currentUser['id'] ?? 0);
    if ($userId <= 0 || $messageId === 0) {
        throw new RuntimeException('invalid_reaction_target');
    }

    if ($messageType === 'directo') {
        if ($messageId < 0) {
            if (!chatSupportsImages($conn)) {
                throw new RuntimeException('invalid_reaction_target');
            }
            $imageId = abs($messageId);
            $stmtTarget = $conn->prepare("
                SELECT emisor_id, receptor_id
                FROM chat_imagenes_temp
                WHERE id = ?
            ");
            if (!$stmtTarget) {
                throw new RuntimeException('invalid_reaction_target');
            }
            $stmtTarget->bind_param('i', $imageId);
            $stmtTarget->execute();
            $target = $stmtTarget->get_result()->fetch_assoc() ?: null;
            $stmtTarget->close();
            if (!$target || !in_array($userId, [(int) ($target['emisor_id'] ?? 0), (int) ($target['receptor_id'] ?? 0)], true)) {
                throw new RuntimeException('invalid_reaction_target');
            }
        } else {
            $stmtTarget = $conn->prepare("
                SELECT emisor_id, receptor_id
                FROM chat_mensajes
                WHERE id = ?
            ");
            if (!$stmtTarget) {
                throw new RuntimeException('invalid_reaction_target');
            }
            $stmtTarget->bind_param('i', $messageId);
            $stmtTarget->execute();
            $target = $stmtTarget->get_result()->fetch_assoc() ?: null;
            $stmtTarget->close();
            if (!$target || !in_array($userId, [(int) ($target['emisor_id'] ?? 0), (int) ($target['receptor_id'] ?? 0)], true)) {
                throw new RuntimeException('invalid_reaction_target');
            }
        }
    } elseif ($messageType === 'grupo') {
        $stmtTarget = $conn->prepare("
            SELECT grupo_tl_id
            FROM chat_grupo_mensajes
            WHERE id = ?
        ");
        if (!$stmtTarget) {
            throw new RuntimeException('invalid_reaction_target');
        }
        $stmtTarget->bind_param('i', $messageId);
        $stmtTarget->execute();
        $target = $stmtTarget->get_result()->fetch_assoc() ?: null;
        $stmtTarget->close();
        if (!$target || !chatGetVisibleGroupRoom($conn, $currentUser, (int) ($target['grupo_tl_id'] ?? 0))) {
            throw new RuntimeException('invalid_reaction_target');
        }
    } else {
        throw new RuntimeException('invalid_reaction_target');
    }

    $stmtExists = $conn->prepare("
        SELECT id
        FROM chat_reacciones
        WHERE tipo_mensaje = ? AND mensaje_id = ? AND usuario_id = ? AND emoji = ?
        LIMIT 1
    ");
    if (!$stmtExists) {
        throw new RuntimeException('reaction_save_failed');
    }
    $stmtExists->bind_param('siis', $messageType, $messageId, $userId, $emoji);
    $stmtExists->execute();
    $existing = $stmtExists->get_result()->fetch_assoc() ?: null;
    $stmtExists->close();

    if ($existing) {
        $reactionId = (int) ($existing['id'] ?? 0);
        $stmtDelete = $conn->prepare("DELETE FROM chat_reacciones WHERE id = ?");
        if (!$stmtDelete) {
            throw new RuntimeException('reaction_save_failed');
        }
        $stmtDelete->bind_param('i', $reactionId);
        $stmtDelete->execute();
        $stmtDelete->close();
    } else {
        $createdAt = chatNowBogotaString();
        $stmtInsert = $conn->prepare("
            INSERT INTO chat_reacciones (tipo_mensaje, mensaje_id, usuario_id, emoji, creado_en)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmtInsert) {
            throw new RuntimeException('reaction_save_failed');
        }
        $stmtInsert->bind_param('siiss', $messageType, $messageId, $userId, $emoji, $createdAt);
        $stmtInsert->execute();
        $stmtInsert->close();
    }

    $map = chatGetReactionSummaries($conn, $messageType, [$messageId], $userId);
    return $map[$messageId] ?? [];
}

function chatNormalizeReplyExcerpt(string $text, int $limit = 120): string
{
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    if ($text === '') {
        return '';
    }

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}

function chatFindDirectReplyTarget(mysqli $conn, int $conversationId, int $currentUserId, int $otherUserId, ?array $replyTarget): ?array
{
    if (!chatSupportsDirectReplies($conn) || empty($replyTarget)) {
        return null;
    }

    $replyType = trim((string) ($replyTarget['type'] ?? ''));
    $replyId = (int) ($replyTarget['id'] ?? 0);
    if (!in_array($replyType, ['texto', 'imagen'], true) || $replyId <= 0) {
        throw new RuntimeException('invalid_reply_target');
    }

    if ($replyType === 'texto') {
        $stmt = $conn->prepare("
            SELECT id, emisor_id, receptor_id, mensaje
            FROM chat_mensajes
            WHERE id = ? AND conversacion_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException('invalid_reply_target');
        }
        $stmt->bind_param('ii', $replyId, $conversationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('invalid_reply_target');
        }

        $participants = [(int) ($row['emisor_id'] ?? 0), (int) ($row['receptor_id'] ?? 0)];
        if (!in_array($currentUserId, $participants, true) || !in_array($otherUserId, $participants, true)) {
            throw new RuntimeException('invalid_reply_target');
        }

        return [
            'type' => 'texto',
            'id' => $replyId,
            'sender_id' => (int) ($row['emisor_id'] ?? 0),
            'excerpt' => chatNormalizeReplyExcerpt((string) ($row['mensaje'] ?? '')),
        ];
    }

    $stmt = $conn->prepare("
        SELECT id, emisor_id, receptor_id, nombre_original
        FROM chat_imagenes_temp
        WHERE id = ? AND conversacion_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('invalid_reply_target');
    }
    $stmt->bind_param('ii', $replyId, $conversationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('invalid_reply_target');
    }

    $participants = [(int) ($row['emisor_id'] ?? 0), (int) ($row['receptor_id'] ?? 0)];
    if (!in_array($currentUserId, $participants, true) || !in_array($otherUserId, $participants, true)) {
        throw new RuntimeException('invalid_reply_target');
    }

    return [
        'type' => 'imagen',
        'id' => $replyId,
        'sender_id' => (int) ($row['emisor_id'] ?? 0),
        'excerpt' => chatNormalizeReplyExcerpt((string) ($row['nombre_original'] ?? 'Imagen temporal')),
    ];
}

function chatBuildDirectReplyPreviewMap(mysqli $conn, int $conversationId, int $currentUserId, array $messages): array
{
    if (!chatSupportsDirectReplies($conn) || empty($messages)) {
        return [];
    }

    $textIds = [];
    $imageIds = [];
    foreach ($messages as $message) {
        $replyType = trim((string) ($message['reply_type'] ?? ''));
        $replyId = (int) ($message['reply_id'] ?? 0);
        if ($replyId <= 0) {
            continue;
        }
        if ($replyType === 'texto') {
            $textIds[$replyId] = $replyId;
        } elseif ($replyType === 'imagen') {
            $imageIds[$replyId] = $replyId;
        }
    }

    $previewMap = [];
    if (!empty($textIds)) {
        $ids = array_values($textIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$conversationId], $ids);
        $stmt = $conn->prepare("
            SELECT id, emisor_id, mensaje
            FROM chat_mensajes
            WHERE conversacion_id = ? AND id IN ($placeholders)
        ");
        if ($stmt instanceof mysqli_stmt) {
            chatBindParams($stmt, $types, $params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $previewMap['texto:' . (int) ($row['id'] ?? 0)] = [
                    'type' => 'texto',
                    'id' => (int) ($row['id'] ?? 0),
                    'sender_id' => (int) ($row['emisor_id'] ?? 0),
                    'excerpt' => chatNormalizeReplyExcerpt((string) ($row['mensaje'] ?? '')),
                    'sender_is_mine' => (int) ($row['emisor_id'] ?? 0) === $currentUserId,
                ];
            }
            $stmt->close();
        }
    }

    if (!empty($imageIds)) {
        $ids = array_values($imageIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$conversationId], $ids);
        $stmt = $conn->prepare("
            SELECT id, emisor_id, nombre_original
            FROM chat_imagenes_temp
            WHERE conversacion_id = ? AND id IN ($placeholders)
        ");
        if ($stmt instanceof mysqli_stmt) {
            chatBindParams($stmt, $types, $params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $previewMap['imagen:' . (int) ($row['id'] ?? 0)] = [
                    'type' => 'imagen',
                    'id' => (int) ($row['id'] ?? 0),
                    'sender_id' => (int) ($row['emisor_id'] ?? 0),
                    'excerpt' => chatNormalizeReplyExcerpt((string) ($row['nombre_original'] ?? 'Imagen temporal')),
                    'sender_is_mine' => (int) ($row['emisor_id'] ?? 0) === $currentUserId,
                ];
            }
            $stmt->close();
        }
    }

    return $previewMap;
}

function chatBindParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $bindParams = [$types];
    foreach ($params as $index => $value) {
        $bindParams[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function chatRoleLabelByType(int $tipo): string
{
    if ($tipo === 1) {
        return t('chat.admin');
    }

    if (in_array($tipo, [4, 5, 8], true)) {
        return t('chat.tl');
    }

    if (in_array($tipo, [9, 10], true)) {
        return t('chat.floor');
    }

    return t('chat.agent');
}

function chatGroupDisplayName(string $tlName): string
{
    $tlName = trim($tlName);
    if ($tlName === '') {
        return t('chat.group_default');
    }

    $parts = preg_split('/\s+/', $tlName) ?: [];
    $shortName = trim((string) ($parts[0] ?? ''));
    if ($shortName === '') {
        $shortName = $tlName;
    }

    return t('chat.group_prefix') . ' ' . $shortName;
}

function chatCanWriteGroupChats(array $currentUser): bool
{
    $tipo = (int) ($currentUser['Tipo'] ?? 0);
    return $tipo === 1 || in_array($tipo, [4, 5, 8, 9, 10], true);
}

function chatGetGroupSeenSessionMap(int $userId): array
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return [];
    }

    $all = $_SESSION['chat_group_seen'] ?? [];
    if (!is_array($all)) {
        return [];
    }

    $map = $all[$userId] ?? [];
    return is_array($map) ? $map : [];
}

function chatSetGroupSeenSessionValue(int $userId, int $groupTlId, int $messageId): void
{
    $userId = (int) $userId;
    $groupTlId = (int) $groupTlId;
    $messageId = (int) $messageId;
    if ($userId <= 0 || $groupTlId <= 0 || $messageId <= 0) {
        return;
    }

    if (!isset($_SESSION['chat_group_seen']) || !is_array($_SESSION['chat_group_seen'])) {
        $_SESSION['chat_group_seen'] = [];
    }

    if (!isset($_SESSION['chat_group_seen'][$userId]) || !is_array($_SESSION['chat_group_seen'][$userId])) {
        $_SESSION['chat_group_seen'][$userId] = [];
    }

    $_SESSION['chat_group_seen'][$userId][$groupTlId] = $messageId;
}

function chatGetGroupSeenMap(mysqli $conn, int $userId): array
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return [];
    }

    $map = [];
    if (chatSupportsGroupReadState($conn)) {
        $stmt = $conn->prepare("
            SELECT grupo_tl_id, ultimo_mensaje_id
            FROM chat_grupo_vistos
            WHERE usuario_id = ?
        ");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $groupTlId = (int) ($row['grupo_tl_id'] ?? 0);
                $messageId = (int) ($row['ultimo_mensaje_id'] ?? 0);
                if ($groupTlId > 0 && $messageId > 0) {
                    $map[$groupTlId] = $messageId;
                }
            }
            $stmt->close();
        }
    }

    foreach (chatGetGroupSeenSessionMap($userId) as $groupTlId => $messageId) {
        $groupTlId = (int) $groupTlId;
        $messageId = (int) $messageId;
        if ($groupTlId > 0 && $messageId > 0 && (!isset($map[$groupTlId]) || $messageId > $map[$groupTlId])) {
            $map[$groupTlId] = $messageId;
        }
    }

    return $map;
}

function chatMarkGroupSeen(mysqli $conn, int $userId, int $groupTlId, int $messageId): void
{
    $userId = (int) $userId;
    $groupTlId = (int) $groupTlId;
    $messageId = (int) $messageId;
    if ($userId <= 0 || $groupTlId <= 0 || $messageId <= 0) {
        return;
    }

    chatSetGroupSeenSessionValue($userId, $groupTlId, $messageId);

    if (!chatSupportsGroupReadState($conn)) {
        return;
    }

    $seenAt = chatNowBogotaString();
    $stmt = $conn->prepare("
        INSERT INTO chat_grupo_vistos (grupo_tl_id, usuario_id, ultimo_mensaje_id, visto_en)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ultimo_mensaje_id = IF(VALUES(ultimo_mensaje_id) > ultimo_mensaje_id, VALUES(ultimo_mensaje_id), ultimo_mensaje_id),
            visto_en = IF(VALUES(ultimo_mensaje_id) > ultimo_mensaje_id, VALUES(visto_en), visto_en)
    ");

    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('iiis', $groupTlId, $userId, $messageId, $seenAt);
        $stmt->execute();
        $stmt->close();
    }
}

function chatMarkVisibleGroupsSeen(mysqli $conn, array $currentUser): void
{
    $currentUserId = (int) ($currentUser['id'] ?? 0);
    if ($currentUserId <= 0 || !chatSupportsGroupChats($conn)) {
        return;
    }

    foreach (chatGetVisibleGroupRooms($conn, $currentUser) as $room) {
        $groupTlId = (int) ($room['id'] ?? 0);
        $lastMessageId = (int) ($room['last_message_id'] ?? 0);
        $lastSenderId = (int) ($room['last_sender_id'] ?? 0);
        if ($groupTlId <= 0 || $lastMessageId <= 0 || $lastSenderId <= 0 || $lastSenderId === $currentUserId) {
            continue;
        }

        chatMarkGroupSeen($conn, $currentUserId, $groupTlId, $lastMessageId);
    }
}

function chatMaybePurge(mysqli $conn): void
{
    $lastRun = (int) ($_SESSION['chat_last_purge_at'] ?? 0);
    $now = time();
    if ($lastRun > 0 && ($now - $lastRun) < 1800) {
        return;
    }

    chatPurgeOldData($conn);
    $_SESSION['chat_last_purge_at'] = $now;
}

function chatPurgeOldData(mysqli $conn): void
{
    $limitDate = chatNowBogota()->sub(new DateInterval('P30D'))->format('Y-m-d H:i:s');

    $stmtDeleteMessages = $conn->prepare("DELETE FROM chat_mensajes WHERE enviado_en < ?");
    if ($stmtDeleteMessages instanceof mysqli_stmt) {
        $stmtDeleteMessages->bind_param('s', $limitDate);
        $stmtDeleteMessages->execute();
        $stmtDeleteMessages->close();
    }

    $conn->query("
        DELETE c
        FROM chat_conversaciones c
        LEFT JOIN chat_mensajes m ON m.conversacion_id = c.id
        WHERE m.id IS NULL
    ");

    if (chatSupportsGroupChats($conn)) {
        $stmtDeleteGroupMessages = $conn->prepare("DELETE FROM chat_grupo_mensajes WHERE enviado_en < ?");
        if ($stmtDeleteGroupMessages instanceof mysqli_stmt) {
            $stmtDeleteGroupMessages->bind_param('s', $limitDate);
            $stmtDeleteGroupMessages->execute();
            $stmtDeleteGroupMessages->close();
        }
    }
}

function chatPurgeOldImages(mysqli $conn): void
{
    if (!chatSupportsImages($conn)) {
        return;
    }

    $limitDate = chatNowBogota()->sub(new DateInterval('P15D'))->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        SELECT id, ruta_relativa
        FROM chat_imagenes_temp
        WHERE creado_en < ?
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $limitDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['id'];
        $relativePath = trim((string) ($row['ruta_relativa'] ?? ''));
        if ($relativePath !== '') {
            $fullPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
    $stmt->close();

    if (!empty($ids)) {
        $idList = implode(',', array_map('intval', $ids));
        $conn->query("DELETE FROM chat_imagenes_temp WHERE id IN ($idList)");
    }
}

function chatGetCurrentUser(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, Nombre, Usuario, Tipo, Grupo, pertenece
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    $row['id'] = (int) $row['id'];
    $row['Tipo'] = (int) $row['Tipo'];
    $row['Grupo'] = (int) $row['Grupo'];
    $row['pertenece'] = trim((string) ($row['pertenece'] ?? ''));

    return $row;
}

function chatFetchUsersByWhere(mysqli $conn, string $where = '', string $types = '', array $params = []): array
{
    $sql = "
        SELECT id, Nombre, Usuario, Tipo, Grupo, pertenece
        FROM users
    ";

    if ($where !== '') {
        $sql .= ' WHERE ' . $where;
    }

    $sql .= ' ORDER BY Nombre ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    chatBindParams($stmt, $types, $params);

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['Tipo'] = (int) $row['Tipo'];
        $row['Grupo'] = (int) $row['Grupo'];
        $rows[$row['id']] = $row;
    }
    $stmt->close();

    return $rows;
}

function chatGetAllowedContacts(mysqli $conn, array $currentUser): array
{
    $currentId = (int) ($currentUser['id'] ?? 0);
    $tipo = (int) ($currentUser['Tipo'] ?? 0);
    $grupo = (int) ($currentUser['Grupo'] ?? 0);
    $pertenece = trim((string) ($currentUser['pertenece'] ?? ''));

    $contacts = [];

    if ($tipo === 1) {
        $contacts = chatFetchUsersByWhere($conn, 'id != ?', 'i', [$currentId]);
    } elseif (in_array($tipo, [4, 5, 8], true)) {
        $teamUsers = chatFetchUsersByWhere($conn, 'Grupo = ? AND id != ?', 'ii', [$currentId, $currentId]);
        $floors = $pertenece !== ''
            ? chatFetchUsersByWhere($conn, 'pertenece = ? AND Tipo IN (9, 10) AND id != ?', 'si', [$pertenece, $currentId])
            : [];
        $admins = chatFetchUsersByWhere($conn, 'Tipo = 1 AND id != ?', 'i', [$currentId]);
        $contacts = $teamUsers + $floors + $admins;
    } elseif (in_array($tipo, [9, 10], true)) {
        $sameCity = $pertenece !== ''
            ? chatFetchUsersByWhere($conn, 'pertenece = ? AND id != ?', 'si', [$pertenece, $currentId])
            : [];
        $allFloors = chatFetchUsersByWhere($conn, 'Tipo IN (9, 10) AND id != ?', 'i', [$currentId]);
        $admins = chatFetchUsersByWhere($conn, 'Tipo = 1 AND id != ?', 'i', [$currentId]);
        $contacts = $sameCity + $allFloors + $admins;
    } else {
        if ($grupo > 0) {
            $teamUsers = chatFetchUsersByWhere($conn, 'Grupo = ? AND id != ?', 'ii', [$grupo, $currentId]);
            foreach ($teamUsers as $row) {
                if (in_array((int) $row['Tipo'], [2, 3, 7], true)) {
                    $contacts[$row['id']] = $row;
                }
            }

            $tl = chatFetchUsersByWhere($conn, 'id = ?', 'i', [$grupo]);
            $contacts += $tl;
        }

        if ($pertenece !== '') {
            $floors = chatFetchUsersByWhere($conn, 'pertenece = ? AND Tipo IN (9, 10) AND id != ?', 'si', [$pertenece, $currentId]);
            $contacts += $floors;
        }

        $admins = chatFetchUsersByWhere($conn, 'Tipo = 1 AND id != ?', 'i', [$currentId]);
        $contacts += $admins;
    }

    unset($contacts[$currentId]);
    uasort($contacts, static fn(array $a, array $b): int => strcasecmp((string) ($a['Nombre'] ?? ''), (string) ($b['Nombre'] ?? '')));

    return $contacts;
}

function chatCanUsersTalk(mysqli $conn, int $currentUserId, int $otherUserId): bool
{
    if ($currentUserId <= 0 || $otherUserId <= 0 || $currentUserId === $otherUserId) {
        return false;
    }

    $currentUser = chatGetCurrentUser($conn, $currentUserId);
    if (!$currentUser) {
        return false;
    }

    $contacts = chatGetAllowedContacts($conn, $currentUser);
    return isset($contacts[$otherUserId]);
}

function chatNormalizePair(int $userA, int $userB): array
{
    return $userA < $userB ? [$userA, $userB] : [$userB, $userA];
}

function chatFindConversation(mysqli $conn, int $userA, int $userB): ?array
{
    [$a, $b] = chatNormalizePair($userA, $userB);

    $stmt = $conn->prepare("
        SELECT id, usuario_a, usuario_b, ultimo_mensaje, ultimo_mensaje_en, ultimo_emisor_id
        FROM chat_conversaciones
        WHERE usuario_a = ? AND usuario_b = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $a, $b);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row ?: null;
}

function chatEnsureConversation(mysqli $conn, int $userA, int $userB): int
{
    $existing = chatFindConversation($conn, $userA, $userB);
    if ($existing) {
        return (int) $existing['id'];
    }

    [$a, $b] = chatNormalizePair($userA, $userB);
    $now = chatNowBogotaString();

    $stmt = $conn->prepare("
        INSERT INTO chat_conversaciones (
            usuario_a, usuario_b, creado_en, actualizado_en, activa
        ) VALUES (?, ?, ?, ?, 1)
    ");

    if (!$stmt) {
        throw new RuntimeException('create_conversation_failed');
    }

    $stmt->bind_param('iiss', $a, $b, $now, $now);
    $stmt->execute();
    $conversationId = (int) $stmt->insert_id;
    $stmt->close();

    return $conversationId;
}

function chatTouchConversation(mysqli $conn, int $conversationId, int $senderId, string $message, string $sentAt): void
{
    $preview = mb_substr(trim($message), 0, 500);
    $stmt = $conn->prepare("
        UPDATE chat_conversaciones
        SET actualizado_en = ?, ultimo_mensaje_en = ?, ultimo_mensaje = ?, ultimo_emisor_id = ?
        WHERE id = ?
    ");

    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('sssii', $sentAt, $sentAt, $preview, $senderId, $conversationId);
        $stmt->execute();
        $stmt->close();
    }
}

function chatTouchConversationImage(mysqli $conn, int $conversationId, int $senderId, string $sentAt): void
{
    chatTouchConversation($conn, $conversationId, $senderId, '[Imagen temporal]', $sentAt);
}

function chatRefreshConversationSummary(mysqli $conn, int $conversationId): void
{
    $latestText = null;
    $stmtText = $conn->prepare("
        SELECT emisor_id, mensaje, enviado_en, id
        FROM chat_mensajes
        WHERE conversacion_id = ?
        ORDER BY enviado_en DESC, id DESC
        LIMIT 1
    ");
    if ($stmtText instanceof mysqli_stmt) {
        $stmtText->bind_param('i', $conversationId);
        $stmtText->execute();
        $latestText = $stmtText->get_result()->fetch_assoc() ?: null;
        $stmtText->close();
    }

    $latestImage = null;
    if (chatSupportsImages($conn)) {
        $stmtImage = $conn->prepare("
            SELECT emisor_id, creado_en AS enviado_en, id
            FROM chat_imagenes_temp
            WHERE conversacion_id = ?
            ORDER BY creado_en DESC, id DESC
            LIMIT 1
        ");
        if ($stmtImage instanceof mysqli_stmt) {
            $stmtImage->bind_param('i', $conversationId);
            $stmtImage->execute();
            $latestImage = $stmtImage->get_result()->fetch_assoc() ?: null;
            $stmtImage->close();
        }
    }

    $latestTextTime = $latestText ? (strtotime((string) ($latestText['enviado_en'] ?? '')) ?: 0) : 0;
    $latestImageTime = $latestImage ? (strtotime((string) ($latestImage['enviado_en'] ?? '')) ?: 0) : 0;

    if ($latestTextTime === 0 && $latestImageTime === 0) {
        $stmtDeleteConversation = $conn->prepare("DELETE FROM chat_conversaciones WHERE id = ?");
        if ($stmtDeleteConversation instanceof mysqli_stmt) {
            $stmtDeleteConversation->bind_param('i', $conversationId);
            $stmtDeleteConversation->execute();
            $stmtDeleteConversation->close();
        }
        return;
    }

    $latestRow = $latestTextTime >= $latestImageTime ? $latestText : $latestImage;
    $preview = $latestRow === $latestText
        ? mb_substr(trim((string) ($latestText['mensaje'] ?? '')), 0, 500)
        : '[Imagen temporal]';
    $latestAt = (string) ($latestRow['enviado_en'] ?? chatNowBogotaString());
    $latestSenderId = (int) ($latestRow['emisor_id'] ?? 0);

    $stmtUpdateConversation = $conn->prepare("
        UPDATE chat_conversaciones
        SET actualizado_en = ?, ultimo_mensaje_en = ?, ultimo_mensaje = ?, ultimo_emisor_id = ?
        WHERE id = ?
    ");
    if ($stmtUpdateConversation instanceof mysqli_stmt) {
        $stmtUpdateConversation->bind_param('sssii', $latestAt, $latestAt, $preview, $latestSenderId, $conversationId);
        $stmtUpdateConversation->execute();
        $stmtUpdateConversation->close();
    }
}

function chatSendMessage(mysqli $conn, int $senderId, int $receiverId, string $message, ?array $replyTarget = null): void
{
    $message = trim($message);
    if ($message === '') {
        throw new RuntimeException('empty_message');
    }

    if (!chatCanUsersTalk($conn, $senderId, $receiverId)) {
        throw new RuntimeException('contact_not_allowed');
    }

    $conversationId = chatEnsureConversation($conn, $senderId, $receiverId);
    $sentAt = chatNowBogotaString();
    $reply = chatFindDirectReplyTarget($conn, $conversationId, $senderId, $receiverId, $replyTarget);

    if (chatSupportsDirectReplies($conn)) {
        $stmt = $conn->prepare("
            INSERT INTO chat_mensajes (
                conversacion_id, emisor_id, receptor_id, mensaje, enviado_en, estado, responde_a_tipo, responde_a_id
            ) VALUES (?, ?, ?, ?, ?, 'enviado', ?, ?)
        ");
    } else {
        $stmt = $conn->prepare("
            INSERT INTO chat_mensajes (
                conversacion_id, emisor_id, receptor_id, mensaje, enviado_en, estado
            ) VALUES (?, ?, ?, ?, ?, 'enviado')
        ");
    }

    if (!$stmt) {
        throw new RuntimeException('insert_message_failed');
    }

    if (chatSupportsDirectReplies($conn)) {
        $replyType = $reply['type'] ?? null;
        $replyId = isset($reply['id']) ? (int) $reply['id'] : null;
        $stmt->bind_param('iiisssi', $conversationId, $senderId, $receiverId, $message, $sentAt, $replyType, $replyId);
    } else {
        $stmt->bind_param('iiiss', $conversationId, $senderId, $receiverId, $message, $sentAt);
    }
    $stmt->execute();
    $stmt->close();

    chatTouchConversation($conn, $conversationId, $senderId, $message, $sentAt);
}

function chatEditOwnDirectMessage(mysqli $conn, int $userId, int $messageId, string $message): array
{
    $message = trim($message);
    if ($message === '') {
        throw new RuntimeException('empty_message');
    }

    $supportsDeleteState = chatSupportsDirectMessageDeleteState($conn);
    $selectDeletedField = $supportsDeleteState ? ', eliminado_en' : '';
    $stmt = $conn->prepare("
        SELECT id, conversacion_id, mensaje{$selectDeletedField}
        FROM chat_mensajes
        WHERE id = ? AND emisor_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('message_lookup_failed');
    }

    $stmt->bind_param('ii', $messageId, $userId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$current) {
        throw new RuntimeException('message_not_found');
    }

    if ($supportsDeleteState && trim((string) ($current['eliminado_en'] ?? '')) !== '') {
        throw new RuntimeException('message_deleted');
    }

    $conversationId = (int) ($current['conversacion_id'] ?? 0);
    $editedAt = chatNowBogotaString();
    if (chatSupportsDirectMessageEditState($conn)) {
        $stmtUpdate = $conn->prepare("
            UPDATE chat_mensajes
            SET mensaje = ?, editado_en = ?
            WHERE id = ? AND emisor_id = ?
        ");
    } else {
        $stmtUpdate = $conn->prepare("
            UPDATE chat_mensajes
            SET mensaje = ?
            WHERE id = ? AND emisor_id = ?
        ");
    }
    if (!$stmtUpdate) {
        throw new RuntimeException('message_update_failed');
    }

    if (chatSupportsDirectMessageEditState($conn)) {
        $stmtUpdate->bind_param('ssii', $message, $editedAt, $messageId, $userId);
    } else {
        $stmtUpdate->bind_param('sii', $message, $messageId, $userId);
    }
    $stmtUpdate->execute();
    $stmtUpdate->close();

    chatRefreshConversationSummary($conn, $conversationId);

    return [
        'id' => $messageId,
        'conversacion_id' => $conversationId,
        'mensaje' => $message,
        'editado_en' => chatSupportsDirectMessageEditState($conn) ? $editedAt : '',
    ];
}

function chatDeleteOwnDirectMessage(mysqli $conn, int $userId, int $messageId): array
{
    $supportsDeleteState = chatSupportsDirectMessageDeleteState($conn);
    $selectDeletedField = $supportsDeleteState ? ', eliminado_en' : '';
    $stmt = $conn->prepare("
        SELECT id, conversacion_id{$selectDeletedField}
        FROM chat_mensajes
        WHERE id = ? AND emisor_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('message_lookup_failed');
    }

    $stmt->bind_param('ii', $messageId, $userId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$current) {
        throw new RuntimeException('message_not_found');
    }

    if ($supportsDeleteState && trim((string) ($current['eliminado_en'] ?? '')) !== '') {
        throw new RuntimeException('message_already_deleted');
    }

    $conversationId = (int) ($current['conversacion_id'] ?? 0);

    $stmtDeleteReactions = $conn->prepare("
        DELETE FROM chat_reacciones
        WHERE tipo_mensaje = 'directo' AND mensaje_id = ? AND usuario_id >= 0
    ");
    if ($stmtDeleteReactions instanceof mysqli_stmt) {
        $stmtDeleteReactions->bind_param('i', $messageId);
        $stmtDeleteReactions->execute();
        $stmtDeleteReactions->close();
    }

    $deletedText = 'Mensaje eliminado';
    $deletedAt = chatNowBogotaString();
    if ($supportsDeleteState) {
        if (chatSupportsDirectMessageEditState($conn)) {
            $stmtDelete = $conn->prepare("
                UPDATE chat_mensajes
                SET mensaje = ?, eliminado_en = ?, editado_en = NULL
                WHERE id = ? AND emisor_id = ?
            ");
        } else {
            $stmtDelete = $conn->prepare("
                UPDATE chat_mensajes
                SET mensaje = ?, eliminado_en = ?
                WHERE id = ? AND emisor_id = ?
            ");
        }
    } else {
        $stmtDelete = $conn->prepare("
            UPDATE chat_mensajes
            SET mensaje = ?
            WHERE id = ? AND emisor_id = ?
        ");
    }
    if (!$stmtDelete) {
        throw new RuntimeException('message_delete_failed');
    }

    if ($supportsDeleteState) {
        $stmtDelete->bind_param('ssii', $deletedText, $deletedAt, $messageId, $userId);
    } else {
        $stmtDelete->bind_param('sii', $deletedText, $messageId, $userId);
    }
    $stmtDelete->execute();
    $stmtDelete->close();

    chatRefreshConversationSummary($conn, $conversationId);

    return [
        'id' => $messageId,
        'conversacion_id' => $conversationId,
        'mensaje' => $deletedText,
        'eliminado_en' => $supportsDeleteState ? $deletedAt : '',
    ];
}

function chatSaveImage(mysqli $conn, int $senderId, int $receiverId, array $file): void
{
    if (!chatSupportsImages($conn)) {
        throw new RuntimeException('images_not_enabled');
    }

    if (!chatCanUsersTalk($conn, $senderId, $receiverId)) {
        throw new RuntimeException('contact_not_allowed');
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('invalid_upload');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('invalid_upload');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('image_too_large');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('image_type_not_allowed');
    }

    $conversationId = chatEnsureConversation($conn, $senderId, $receiverId);
    $sentAt = chatNowBogotaString();
    $extension = $allowed[$mime];
    $safeName = ($mime === 'application/pdf' ? 'doc_' : 'img_') . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $diskDir = chatEnsureImagesDirectory();
    $diskPath = $diskDir . '/' . $safeName;

    if (!move_uploaded_file($tmpName, $diskPath)) {
        throw new RuntimeException('move_upload_failed');
    }

    $relativePath = chatImagesPublicRelativePath() . '/' . $safeName;
    $originalName = trim((string) ($file['name'] ?? ($mime === 'application/pdf' ? 'archivo.pdf' : 'imagen')));

    $stmt = $conn->prepare("
        INSERT INTO chat_imagenes_temp (
            conversacion_id, emisor_id, receptor_id, nombre_original,
            nombre_archivo, ruta_relativa, mime_type, tamano_bytes, creado_en
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        @unlink($diskPath);
        throw new RuntimeException('insert_image_failed');
    }

    $stmt->bind_param(
        'iiissssis',
        $conversationId,
        $senderId,
        $receiverId,
        $originalName,
        $safeName,
        $relativePath,
        $mime,
        $size,
        $sentAt
    );
    $stmt->execute();
    $stmt->close();

    chatTouchConversationImage($conn, $conversationId, $senderId, $sentAt);
}

function chatSaveGroupImage(mysqli $conn, array $currentUser, int $groupTlId, array $file): void
{
    if (!chatSupportsGroupChats($conn)) {
        throw new RuntimeException('group_chat_not_enabled');
    }

    if (!chatSupportsGroupImages($conn)) {
        throw new RuntimeException('group_images_not_enabled');
    }

    $room = chatGetVisibleGroupRoom($conn, $currentUser, $groupTlId);
    if (!$room) {
        throw new RuntimeException('group_not_allowed');
    }

    if (!chatCanWriteGroupChats($currentUser)) {
        throw new RuntimeException('group_read_only');
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('invalid_upload');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('invalid_upload');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('image_too_large');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('image_type_not_allowed');
    }

    $sentAt = chatNowBogotaString();
    $senderId = (int) ($currentUser['id'] ?? 0);
    $placeholderMessage = $mime === 'application/pdf' ? '[PDF temporal]' : '[Imagen temporal]';
    $messageStmt = $conn->prepare("
        INSERT INTO chat_grupo_mensajes (
            grupo_tl_id, emisor_id, mensaje, enviado_en
        ) VALUES (?, ?, ?, ?)
    ");

    if (!$messageStmt) {
        throw new RuntimeException('insert_group_message_failed');
    }

    $messageStmt->bind_param('iiss', $groupTlId, $senderId, $placeholderMessage, $sentAt);
    $messageStmt->execute();
    $groupMessageId = (int) $conn->insert_id;
    $messageStmt->close();

    if ($groupMessageId <= 0) {
        throw new RuntimeException('insert_group_message_failed');
    }

    $extension = $allowed[$mime];
    $safeName = ($mime === 'application/pdf' ? 'doc_' : 'img_') . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $diskDir = chatEnsureImagesDirectory();
    $diskPath = $diskDir . '/' . $safeName;

    if (!move_uploaded_file($tmpName, $diskPath)) {
        $conn->query("DELETE FROM chat_grupo_mensajes WHERE id = " . $groupMessageId);
        throw new RuntimeException('move_upload_failed');
    }

    $relativePath = chatImagesPublicRelativePath() . '/' . $safeName;
    $originalName = trim((string) ($file['name'] ?? ($mime === 'application/pdf' ? 'archivo.pdf' : 'imagen')));

    $stmt = $conn->prepare("
        INSERT INTO chat_imagenes_temp (
            conversacion_id, grupo_tl_id, grupo_mensaje_id, emisor_id, receptor_id,
            nombre_original, nombre_archivo, ruta_relativa, mime_type, tamano_bytes, creado_en
        ) VALUES (NULL, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        @unlink($diskPath);
        $conn->query("DELETE FROM chat_grupo_mensajes WHERE id = " . $groupMessageId);
        throw new RuntimeException('insert_group_image_failed');
    }

    $stmt->bind_param(
        'iiissssis',
        $groupTlId,
        $groupMessageId,
        $senderId,
        $originalName,
        $safeName,
        $relativePath,
        $mime,
        $size,
        $sentAt
    );
    $stmt->execute();
    $stmt->close();
}

function chatGetConversationUnreadCount(mysqli $conn, int $conversationId, int $userId): int
{
    $total = 0;

    $stmtText = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM chat_mensajes
        WHERE conversacion_id = ? AND receptor_id = ? AND leido_en IS NULL
    ");
    if ($stmtText instanceof mysqli_stmt) {
        $stmtText->bind_param('ii', $conversationId, $userId);
        $stmtText->execute();
        $total += (int) (($stmtText->get_result()->fetch_assoc()['total'] ?? 0));
        $stmtText->close();
    }

    if (chatSupportsImages($conn)) {
        $stmtImages = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM chat_imagenes_temp
            WHERE conversacion_id = ? AND receptor_id = ? AND visto_en IS NULL
        ");
        if ($stmtImages instanceof mysqli_stmt) {
            $stmtImages->bind_param('ii', $conversationId, $userId);
            $stmtImages->execute();
            $total += (int) (($stmtImages->get_result()->fetch_assoc()['total'] ?? 0));
            $stmtImages->close();
        }
    }

    return $total;
}

function chatMarkDirectConversationSeen(mysqli $conn, int $conversationId, int $userId): void
{
    if ($conversationId <= 0 || $userId <= 0) {
        return;
    }

    $readAt = chatNowBogotaString();

    $stmtMarkRead = $conn->prepare("
        UPDATE chat_mensajes
        SET leido_en = ?, estado = 'leido'
        WHERE conversacion_id = ? AND receptor_id = ? AND leido_en IS NULL
    ");
    if ($stmtMarkRead instanceof mysqli_stmt) {
        $stmtMarkRead->bind_param('sii', $readAt, $conversationId, $userId);
        $stmtMarkRead->execute();
        $stmtMarkRead->close();
    }

    if (!chatSupportsImages($conn)) {
        return;
    }

    $stmtMarkImages = $conn->prepare("
        UPDATE chat_imagenes_temp
        SET visto_en = COALESCE(visto_en, ?)
        WHERE conversacion_id = ? AND receptor_id = ? AND visto_en IS NULL
    ");
    if ($stmtMarkImages instanceof mysqli_stmt) {
        $stmtMarkImages->bind_param('sii', $readAt, $conversationId, $userId);
        $stmtMarkImages->execute();
        $stmtMarkImages->close();
    }
}

function chatMarkAllDirectSeen(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $readAt = chatNowBogotaString();

    $stmtMessages = $conn->prepare("
        UPDATE chat_mensajes
        SET leido_en = ?, estado = 'leido'
        WHERE receptor_id = ? AND leido_en IS NULL
    ");
    if ($stmtMessages instanceof mysqli_stmt) {
        $stmtMessages->bind_param('si', $readAt, $userId);
        $stmtMessages->execute();
        $stmtMessages->close();
    }

    if (!chatSupportsImages($conn)) {
        return;
    }

    $stmtImages = $conn->prepare("
        UPDATE chat_imagenes_temp
        SET visto_en = COALESCE(visto_en, ?)
        WHERE receptor_id = ? AND visto_en IS NULL
    ");
    if ($stmtImages instanceof mysqli_stmt) {
        $stmtImages->bind_param('si', $readAt, $userId);
        $stmtImages->execute();
        $stmtImages->close();
    }
}

function chatGetConversationMessages(mysqli $conn, int $currentUserId, int $otherUserId, bool $markRead = true): array
{
    if (!chatCanUsersTalk($conn, $currentUserId, $otherUserId)) {
        return [];
    }

    $conversation = chatFindConversation($conn, $currentUserId, $otherUserId);
    if (!$conversation) {
        return [];
    }

    $conversationId = (int) $conversation['id'];
    if ($markRead) {
        chatMarkDirectConversationSeen($conn, $conversationId, $currentUserId);
    }

    $supportsDirectReplies = chatSupportsDirectReplies($conn);
    $supportsEditState = chatSupportsDirectMessageEditState($conn);
    $supportsDeleteState = chatSupportsDirectMessageDeleteState($conn);
    $extraColumns = [];
    if ($supportsEditState) {
        $extraColumns[] = 'editado_en';
    }
    if ($supportsDeleteState) {
        $extraColumns[] = 'eliminado_en';
    }
    $extraSelect = empty($extraColumns) ? '' : ', ' . implode(', ', $extraColumns);

    if ($supportsDirectReplies) {
        $stmt = $conn->prepare("
            SELECT id, emisor_id, receptor_id, mensaje, enviado_en, leido_en, estado, responde_a_tipo, responde_a_id{$extraSelect}
            FROM chat_mensajes
            WHERE conversacion_id = ?
            ORDER BY enviado_en ASC, id ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT id, emisor_id, receptor_id, mensaje, enviado_en, leido_en, estado{$extraSelect}
            FROM chat_mensajes
            WHERE conversacion_id = ?
            ORDER BY enviado_en ASC, id ASC
        ");
    }

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $deletedText = trim((string) ($row['mensaje'] ?? ''));
        $deletedAt = trim((string) ($row['eliminado_en'] ?? ''));
        $editedAt = trim((string) ($row['editado_en'] ?? ''));
        $isDeleted = $deletedAt !== '' || $deletedText === 'Mensaje eliminado';
        $messages[] = [
            'kind' => 'text',
            'id' => (int) $row['id'],
            'emisor_id' => (int) $row['emisor_id'],
            'receptor_id' => (int) $row['receptor_id'],
            'mensaje' => (string) $row['mensaje'],
            'enviado_en' => (string) $row['enviado_en'],
            'leido_en' => (string) ($row['leido_en'] ?? ''),
            'estado' => (string) ($row['estado'] ?? 'enviado'),
            'mine' => (int) $row['emisor_id'] === $currentUserId,
            'reply_type' => trim((string) ($row['responde_a_tipo'] ?? '')),
            'reply_id' => (int) ($row['responde_a_id'] ?? 0),
            'editado_en' => $editedAt,
            'eliminado_en' => $deletedAt,
            'edited' => !$isDeleted && $editedAt !== '',
            'deleted' => $isDeleted,
        ];
    }
    $stmt->close();

    if (chatSupportsImages($conn)) {
        $stmtImages = $conn->prepare("
            SELECT id, emisor_id, receptor_id, nombre_original, nombre_archivo, creado_en, visto_en, mime_type
            FROM chat_imagenes_temp
            WHERE conversacion_id = ?
            ORDER BY creado_en ASC, id ASC
        ");
        if ($stmtImages instanceof mysqli_stmt) {
            $stmtImages->bind_param('i', $conversationId);
            $stmtImages->execute();
            $resultImages = $stmtImages->get_result();
            while ($row = $resultImages->fetch_assoc()) {
                $mimeType = trim((string) ($row['mime_type'] ?? 'application/octet-stream'));
                $originalName = trim((string) ($row['nombre_original'] ?? 'Imagen temporal'));
                $storedFileName = trim((string) ($row['nombre_archivo'] ?? ''));
                $messages[] = [
                    'kind' => 'image',
                    'id' => (int) $row['id'],
                    'emisor_id' => (int) $row['emisor_id'],
                    'receptor_id' => (int) $row['receptor_id'],
                    'mensaje' => $originalName,
                    'enviado_en' => (string) $row['creado_en'],
                    'leido_en' => (string) ($row['visto_en'] ?? ''),
                    'estado' => trim((string) ($row['visto_en'] ?? '')) !== '' ? 'leido' : 'enviado',
                    'mine' => (int) $row['emisor_id'] === $currentUserId,
                    'image_id' => (int) $row['id'],
                    'image_name' => $originalName,
                    'mime_type' => $mimeType,
                    'is_pdf' => chatIsPdfAttachment($mimeType, $originalName, $storedFileName),
                ];
            }
            $stmtImages->close();
        }
    }

    usort($messages, static function (array $a, array $b): int {
        $aTime = strtotime((string) ($a['enviado_en'] ?? '')) ?: 0;
        $bTime = strtotime((string) ($b['enviado_en'] ?? '')) ?: 0;
        if ($aTime !== $bTime) {
            return $aTime <=> $bTime;
        }
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    $replyMap = chatBuildDirectReplyPreviewMap($conn, $conversationId, $currentUserId, $messages);
    foreach ($messages as &$message) {
        $replyType = trim((string) ($message['reply_type'] ?? ''));
        $replyId = (int) ($message['reply_id'] ?? 0);
        if ($replyId > 0 && isset($replyMap[$replyType . ':' . $replyId])) {
            $message['reply_preview'] = $replyMap[$replyType . ':' . $replyId];
        } else {
            $message['reply_preview'] = null;
        }
    }
    unset($message);

    return chatAttachReactionsToMessages($conn, $messages, 'directo', $currentUserId);
}

function chatGetContactRows(mysqli $conn, array $currentUser): array
{
    $currentId = (int) ($currentUser['id'] ?? 0);
    $contacts = chatGetAllowedContacts($conn, $currentUser);
    if (empty($contacts)) {
        return [];
    }

    $rows = [];
    foreach ($contacts as $contact) {
        $contactId = (int) $contact['id'];
        $conversation = chatFindConversation($conn, $currentId, $contactId);

        $unreadCount = 0;
        if ($conversation) {
            $unreadCount = chatGetConversationUnreadCount($conn, (int) $conversation['id'], $currentId);
        }

        $rows[] = [
            'id' => $contactId,
            'name' => (string) ($contact['Nombre'] ?? ''),
            'username' => (string) ($contact['Usuario'] ?? ''),
            'role' => chatRoleLabelByType((int) ($contact['Tipo'] ?? 0)),
            'city' => trim((string) ($contact['pertenece'] ?? '')),
            'last_message' => (string) ($conversation['ultimo_mensaje'] ?? ''),
            'last_message_at' => (string) ($conversation['ultimo_mensaje_en'] ?? ''),
            'last_sender_id' => (int) ($conversation['ultimo_emisor_id'] ?? 0),
            'unread_count' => $unreadCount,
            'has_conversation' => $conversation !== null,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $aStamp = $a['last_message_at'] !== '' ? strtotime((string) $a['last_message_at']) : 0;
        $bStamp = $b['last_message_at'] !== '' ? strtotime((string) $b['last_message_at']) : 0;
        if ($aStamp !== $bStamp) {
            return $bStamp <=> $aStamp;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $rows;
}

function chatFormatRelative(?string $dateTime): string
{
    $dateTime = trim((string) $dateTime);
    if ($dateTime === '') {
        return '';
    }

    try {
        $value = new DateTimeImmutable($dateTime, new DateTimeZone('America/Bogota'));
    } catch (Throwable $e) {
        return $dateTime;
    }

    $now = chatNowBogota();
    $diff = $now->getTimestamp() - $value->getTimestamp();
    if ($diff < 60) {
        return t('chat.just_now');
    }

    if ($diff < 3600) {
        return floor($diff / 60) . ' min';
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . ' h';
    }

    return $value->format('d/m H:i');
}

function chatGetUnreadSummary(mysqli $conn, int $userId): array
{
    $summary = [
        'unread_messages' => 0,
        'unread_conversations' => 0,
        'direct_unread_conversations' => 0,
        'group_unread_conversations' => 0,
        'latest_message_id' => 0,
        'latest_notification_key' => '',
        'latest_target_type' => 'direct',
        'latest_target_id' => 0,
        'latest_target_name' => '',
        'latest_sender_id' => 0,
        'latest_sender_name' => '',
        'latest_message' => '',
        'latest_sent_at' => '',
    ];

    if ($userId <= 0) {
        return $summary;
    }

    $currentUser = chatGetCurrentUser($conn, $userId);
    if (!$currentUser) {
        return $summary;
    }

    $unreadItems = [];

    $stmtText = $conn->prepare("
        SELECT m.id, m.conversacion_id, m.emisor_id, m.mensaje, m.enviado_en, u.Nombre AS emisor_nombre, 'text' AS tipo
        FROM chat_mensajes m
        INNER JOIN users u ON u.id = m.emisor_id
        WHERE m.receptor_id = ? AND m.leido_en IS NULL
    ");
    if ($stmtText instanceof mysqli_stmt) {
        $stmtText->bind_param('i', $userId);
        $stmtText->execute();
        $resultText = $stmtText->get_result();
        while ($row = $resultText->fetch_assoc()) {
            $unreadItems[] = [
                'id' => (int) $row['id'],
                'conversacion_id' => (int) $row['conversacion_id'],
                'emisor_id' => (int) $row['emisor_id'],
                'mensaje' => trim((string) ($row['mensaje'] ?? '')),
                'enviado_en' => trim((string) ($row['enviado_en'] ?? '')),
                'emisor_nombre' => trim((string) ($row['emisor_nombre'] ?? '')),
                'tipo' => 'text',
            ];
        }
        $stmtText->close();
    }

    if (chatSupportsImages($conn)) {
        $stmtImages = $conn->prepare("
            SELECT i.id, i.conversacion_id, i.emisor_id, i.nombre_original, i.creado_en, u.Nombre AS emisor_nombre
            FROM chat_imagenes_temp i
            INNER JOIN users u ON u.id = i.emisor_id
            WHERE i.receptor_id = ? AND i.visto_en IS NULL
        ");
        if ($stmtImages instanceof mysqli_stmt) {
            $stmtImages->bind_param('i', $userId);
            $stmtImages->execute();
            $resultImages = $stmtImages->get_result();
            while ($row = $resultImages->fetch_assoc()) {
                $unreadItems[] = [
                    'id' => (int) $row['id'],
                    'conversacion_id' => (int) $row['conversacion_id'],
                    'emisor_id' => (int) $row['emisor_id'],
                    'mensaje' => '[Imagen temporal] ' . trim((string) ($row['nombre_original'] ?? 'Imagen temporal')),
                    'enviado_en' => trim((string) ($row['creado_en'] ?? '')),
                    'emisor_nombre' => trim((string) ($row['emisor_nombre'] ?? '')),
                    'tipo' => 'image',
                ];
            }
            $stmtImages->close();
        }
    }

    $summary['unread_messages'] = count($unreadItems);
    $conversationSet = [];
    foreach ($unreadItems as $item) {
        $conversationSet[(int) $item['conversacion_id']] = true;
    }
    $summary['direct_unread_conversations'] = count($conversationSet);
    $summary['unread_conversations'] = $summary['direct_unread_conversations'];

    if (!empty($unreadItems)) {
        usort($unreadItems, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['enviado_en'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['enviado_en'] ?? '')) ?: 0;
            if ($aTime !== $bTime) {
                return $bTime <=> $aTime;
            }
            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        $latest = $unreadItems[0];
        $summary['latest_message_id'] = (int) ($latest['id'] ?? 0);
        $summary['latest_notification_key'] = 'direct-' . (int) ($latest['id'] ?? 0);
        $summary['latest_target_type'] = 'direct';
        $summary['latest_target_id'] = (int) ($latest['emisor_id'] ?? 0);
        $summary['latest_target_name'] = trim((string) ($latest['emisor_nombre'] ?? ''));
        $summary['latest_sender_id'] = (int) ($latest['emisor_id'] ?? 0);
        $summary['latest_sender_name'] = trim((string) ($latest['emisor_nombre'] ?? ''));
        $summary['latest_message'] = trim((string) ($latest['mensaje'] ?? ''));
        $summary['latest_sent_at'] = trim((string) ($latest['enviado_en'] ?? ''));
    }

    if (chatSupportsGroupChats($conn)) {
        $groupRooms = chatGetVisibleGroupRooms($conn, $currentUser);
        $groupSeenMap = chatGetGroupSeenMap($conn, $userId);
        $latestGroup = null;
        $groupUnreadCount = 0;

        foreach ($groupRooms as $room) {
            $groupTlId = (int) ($room['id'] ?? 0);
            if ($groupTlId <= 0) {
                continue;
            }

            $stmtGroup = $conn->prepare("
                SELECT m.id, m.grupo_tl_id, m.emisor_id, m.mensaje, m.enviado_en, u.Nombre AS emisor_nombre
                FROM chat_grupo_mensajes m
                INNER JOIN users u ON u.id = m.emisor_id
                WHERE m.grupo_tl_id = ? AND m.emisor_id != ?
                ORDER BY m.enviado_en DESC, m.id DESC
                LIMIT 1
            ");

            if (!$stmtGroup) {
                continue;
            }

            $stmtGroup->bind_param('ii', $groupTlId, $userId);
            $stmtGroup->execute();
            $row = $stmtGroup->get_result()->fetch_assoc() ?: null;
            $stmtGroup->close();

            if (!$row) {
                continue;
            }

            $latestGroupMessageId = (int) ($row['id'] ?? 0);
            $lastSeenId = (int) ($groupSeenMap[$groupTlId] ?? 0);
            if ((int) ($row['emisor_id'] ?? 0) !== $userId && $latestGroupMessageId > $lastSeenId) {
                $groupUnreadCount++;
            } else {
                continue;
            }

            if ($latestGroup === null) {
                $latestGroup = $row + ['room_name' => (string) ($room['name'] ?? '')];
                continue;
            }

            $currentStamp = strtotime((string) ($row['enviado_en'] ?? '')) ?: 0;
            $latestStamp = strtotime((string) ($latestGroup['enviado_en'] ?? '')) ?: 0;
            if ($currentStamp > $latestStamp || ($currentStamp === $latestStamp && (int) ($row['id'] ?? 0) > (int) ($latestGroup['id'] ?? 0))) {
                $latestGroup = $row + ['room_name' => (string) ($room['name'] ?? '')];
            }
        }

        if ($latestGroup) {
            $groupStamp = strtotime((string) ($latestGroup['enviado_en'] ?? '')) ?: 0;
            $currentStamp = strtotime((string) ($summary['latest_sent_at'] ?? '')) ?: 0;
            $groupIsLatest = $groupStamp > $currentStamp
                || ($groupStamp === $currentStamp && ('group-' . (int) ($latestGroup['id'] ?? 0)) !== ($summary['latest_notification_key'] ?? ''));

            if ($groupIsLatest) {
                $summary['latest_message_id'] = (int) ($latestGroup['id'] ?? 0);
                $summary['latest_notification_key'] = 'group-' . (int) ($latestGroup['id'] ?? 0);
                $summary['latest_target_type'] = 'group';
                $summary['latest_target_id'] = (int) ($latestGroup['grupo_tl_id'] ?? 0);
                $summary['latest_target_name'] = trim((string) ($latestGroup['room_name'] ?? ''));
                $summary['latest_sender_id'] = (int) ($latestGroup['emisor_id'] ?? 0);
                $summary['latest_sender_name'] = trim((string) ($latestGroup['emisor_nombre'] ?? ''));
                $summary['latest_message'] = trim((string) ($latestGroup['mensaje'] ?? ''));
                $summary['latest_sent_at'] = trim((string) ($latestGroup['enviado_en'] ?? ''));
            }
        }

        $summary['group_unread_conversations'] = $groupUnreadCount;
        $summary['unread_conversations'] += $groupUnreadCount;
        $summary['unread_messages'] += $groupUnreadCount;
    }

    return $summary;
}

function chatFindImageForUser(mysqli $conn, int $imageId, int $userId): ?array
{
    if (!chatSupportsImages($conn) || $imageId <= 0 || $userId <= 0) {
        return null;
    }

    if (chatSupportsGroupImages($conn)) {
        $stmt = $conn->prepare("
            SELECT i.*, c.usuario_a, c.usuario_b
            FROM chat_imagenes_temp i
            LEFT JOIN chat_conversaciones c ON c.id = i.conversacion_id
            WHERE i.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $imageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        $conversationId = (int) ($row['conversacion_id'] ?? 0);
        if ($conversationId > 0) {
            return ((int) ($row['emisor_id'] ?? 0) === $userId || (int) ($row['receptor_id'] ?? 0) === $userId) ? $row : null;
        }

        $groupTlId = (int) ($row['grupo_tl_id'] ?? 0);
        if ($groupTlId <= 0) {
            return null;
        }

        $currentUser = chatGetCurrentUser($conn, $userId);
        if (!$currentUser) {
            return null;
        }

        return chatCanUserSeeGroupRoom($conn, $currentUser, $groupTlId) ? $row : null;
    }

    $stmt = $conn->prepare("
        SELECT i.*, c.usuario_a, c.usuario_b
        FROM chat_imagenes_temp i
        INNER JOIN chat_conversaciones c ON c.id = i.conversacion_id
        WHERE i.id = ? AND (i.emisor_id = ? OR i.receptor_id = ?)
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iii', $imageId, $userId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row ?: null;
}

function chatMarkImageViewed(mysqli $conn, int $imageId, int $userId): void
{
    if (!chatSupportsImages($conn)) {
        return;
    }

    $viewedAt = chatNowBogotaString();
    $stmt = $conn->prepare("
        UPDATE chat_imagenes_temp
        SET visto_en = COALESCE(visto_en, ?)
        WHERE id = ? AND receptor_id = ?
    ");
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('sii', $viewedAt, $imageId, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function chatAdminListImages(mysqli $conn): array
{
    $images = [];
    $diskDir = chatEnsureImagesDirectory();
    $byFileName = [];

    if (chatSupportsImages($conn)) {
        $sql = "
            SELECT
                i.id,
                i.nombre_original,
                i.nombre_archivo,
                i.ruta_relativa,
                i.mime_type,
                i.tamano_bytes,
                i.creado_en,
                i.visto_en,
                e.Nombre AS emisor_nombre,
                r.Nombre AS receptor_nombre
            FROM chat_imagenes_temp i
            LEFT JOIN users e ON e.id = i.emisor_id
            LEFT JOIN users r ON r.id = i.receptor_id
            ORDER BY i.creado_en DESC, i.id DESC
        ";
        $result = $conn->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $fileName = trim((string) ($row['nombre_archivo'] ?? ''));
                if ($fileName === '') {
                    continue;
                }
                $byFileName[$fileName] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'original_name' => trim((string) ($row['nombre_original'] ?? '')),
                    'file_name' => $fileName,
                    'relative_path' => trim((string) ($row['ruta_relativa'] ?? '')),
                    'mime_type' => trim((string) ($row['mime_type'] ?? 'application/octet-stream')),
                    'size_bytes' => (int) ($row['tamano_bytes'] ?? 0),
                    'created_at' => trim((string) ($row['creado_en'] ?? '')),
                    'viewed_at' => trim((string) ($row['visto_en'] ?? '')),
                    'sender_name' => trim((string) ($row['emisor_nombre'] ?? '')),
                    'receiver_name' => trim((string) ($row['receptor_nombre'] ?? '')),
                    'has_db_record' => true,
                ];
            }
            $result->free();
        }
    }

    $files = is_dir($diskDir) ? scandir($diskDir) : [];
    foreach ($files as $fileName) {
        if ($fileName === '.' || $fileName === '..') {
            continue;
        }

        $fullPath = chatImageAbsolutePathFromFileName($fileName);
        if (!is_file($fullPath)) {
            continue;
        }

        $relativePath = chatImagesPublicRelativePath() . '/' . basename($fileName);
        $fileMtime = @filemtime($fullPath) ?: 0;
        $images[] = array_merge([
            'id' => 0,
            'original_name' => basename($fileName),
            'file_name' => basename($fileName),
            'relative_path' => $relativePath,
            'mime_type' => function_exists('mime_content_type') ? (string) @mime_content_type($fullPath) : 'application/octet-stream',
            'size_bytes' => (int) (@filesize($fullPath) ?: 0),
            'created_at' => $fileMtime > 0 ? date('Y-m-d H:i:s', $fileMtime) : '',
            'viewed_at' => '',
            'sender_name' => '',
            'receiver_name' => '',
            'has_db_record' => false,
        ], $byFileName[basename($fileName)] ?? []);
    }

    usort($images, static function (array $a, array $b): int {
        $aStamp = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $bStamp = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        if ($aStamp !== $bStamp) {
            return $bStamp <=> $aStamp;
        }
        return strcasecmp((string) ($a['file_name'] ?? ''), (string) ($b['file_name'] ?? ''));
    });

    return $images;
}

function chatAdminDeleteImage(mysqli $conn, string $fileName): array
{
    $safeName = basename(str_replace('\\', '/', $fileName));
    if ($safeName === '') {
        throw new RuntimeException('invalid_file');
    }

    $fullPath = chatImageAbsolutePathFromFileName($safeName);
    $deletedFile = false;
    $deletedRows = 0;

    if (chatSupportsImages($conn)) {
        $stmt = $conn->prepare("DELETE FROM chat_imagenes_temp WHERE nombre_archivo = ?");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('s', $safeName);
            $stmt->execute();
            $deletedRows = (int) $stmt->affected_rows;
            $stmt->close();
        }
    }

    if (is_file($fullPath)) {
        $deletedFile = @unlink($fullPath);
        if (!$deletedFile) {
            throw new RuntimeException('delete_file_failed');
        }
    }

    return [
        'deleted_file' => $deletedFile,
        'deleted_rows' => $deletedRows,
        'file_name' => $safeName,
    ];
}

function chatGetVisibleGroupRooms(mysqli $conn, array $currentUser): array
{
    if (!chatSupportsGroupChats($conn)) {
        return [];
    }

    $currentId = (int) ($currentUser['id'] ?? 0);
    $tipo = (int) ($currentUser['Tipo'] ?? 0);
    $grupo = (int) ($currentUser['Grupo'] ?? 0);
    $pertenece = trim((string) ($currentUser['pertenece'] ?? ''));

    if ($currentId <= 0) {
        return [];
    }

    $where = 'Tipo IN (1, 4, 5, 8)';
    $types = '';
    $params = [];

    if ($tipo === 1) {
        // Admin ve todos los grupos.
    } elseif (in_array($tipo, [4, 5, 8], true)) {
        $where .= ' AND id = ?';
        $types = 'i';
        $params[] = $currentId;
    } elseif (in_array($tipo, [9, 10], true)) {
        if ($pertenece === '' && $grupo <= 0) {
            return [];
        }
        if ($pertenece !== '' && $grupo > 0) {
            $where .= ' AND (pertenece = ? OR id = ?)';
            $types = 'si';
            $params[] = $pertenece;
            $params[] = $grupo;
        } elseif ($pertenece !== '') {
            $where .= ' AND pertenece = ?';
            $types = 's';
            $params[] = $pertenece;
        } else {
            $where .= ' AND id = ?';
            $types = 'i';
            $params[] = $grupo;
        }
    } else {
        if ($grupo <= 0) {
            return [];
        }
        $where .= ' AND id = ?';
        $types = 'i';
        $params[] = $grupo;
    }

    $groupLeads = chatFetchUsersByWhere($conn, $where, $types, $params);
    if (empty($groupLeads)) {
        return [];
    }

    $groupSeenMap = chatGetGroupSeenMap($conn, $currentId);
    $rows = [];
    foreach ($groupLeads as $groupLead) {
        $groupTlId = (int) ($groupLead['id'] ?? 0);
        if ($groupTlId <= 0) {
            continue;
        }

        $lastMessageId = 0;
        $lastMessage = '';
        $lastMessageAt = '';
        $lastSenderId = 0;
        $lastSenderName = '';

        $stmt = $conn->prepare("
            SELECT m.id, m.mensaje, m.enviado_en, m.emisor_id, u.Nombre AS emisor_nombre
            FROM chat_grupo_mensajes m
            INNER JOIN users u ON u.id = m.emisor_id
            WHERE m.grupo_tl_id = ?
            ORDER BY m.enviado_en DESC, m.id DESC
            LIMIT 1
        ");

        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $groupTlId);
            $stmt->execute();
            $lastRow = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if ($lastRow) {
                $lastMessageId = (int) ($lastRow['id'] ?? 0);
                $lastMessage = trim((string) ($lastRow['mensaje'] ?? ''));
                $lastMessageAt = trim((string) ($lastRow['enviado_en'] ?? ''));
                $lastSenderId = (int) ($lastRow['emisor_id'] ?? 0);
                $lastSenderName = trim((string) ($lastRow['emisor_nombre'] ?? ''));
            }
        }

        $lastSeenId = (int) ($groupSeenMap[$groupTlId] ?? 0);
        $unreadCount = ($lastSenderId > 0 && $lastSenderId !== $currentId && $lastMessageId > $lastSeenId) ? 1 : 0;

        $rows[] = [
            'id' => $groupTlId,
            'name' => chatGroupDisplayName((string) ($groupLead['Nombre'] ?? '')),
            'tl_name' => (string) ($groupLead['Nombre'] ?? ''),
            'username' => (string) ($groupLead['Usuario'] ?? ''),
            'city' => trim((string) ($groupLead['pertenece'] ?? '')),
            'role' => chatRoleLabelByType((int) ($groupLead['Tipo'] ?? 0)),
            'last_message_id' => $lastMessageId,
            'last_message' => $lastMessage,
            'last_message_at' => $lastMessageAt,
            'last_sender_id' => $lastSenderId,
            'last_sender_name' => $lastSenderName,
            'unread_count' => $unreadCount,
            'can_write' => chatCanWriteGroupChats($currentUser),
            'read_only' => !chatCanWriteGroupChats($currentUser),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $aStamp = $a['last_message_at'] !== '' ? strtotime((string) $a['last_message_at']) : 0;
        $bStamp = $b['last_message_at'] !== '' ? strtotime((string) $b['last_message_at']) : 0;
        if ($aStamp !== $bStamp) {
            return $bStamp <=> $aStamp;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $rows;
}

function chatGetVisibleGroupRoom(mysqli $conn, array $currentUser, int $groupTlId): ?array
{
    if ($groupTlId <= 0) {
        return null;
    }

    foreach (chatGetVisibleGroupRooms($conn, $currentUser) as $room) {
        if ((int) ($room['id'] ?? 0) === $groupTlId) {
            return $room;
        }
    }

    return null;
}

function chatCanUserSeeGroupRoom(mysqli $conn, array $currentUser, int $groupTlId): bool
{
    $groupTlId = (int) $groupTlId;
    if ($groupTlId <= 0) {
        return false;
    }

    $currentId = (int) ($currentUser['id'] ?? 0);
    $tipo = (int) ($currentUser['Tipo'] ?? 0);
    $grupo = (int) ($currentUser['Grupo'] ?? 0);
    $pertenece = trim((string) ($currentUser['pertenece'] ?? ''));

    if ($currentId <= 0) {
        return false;
    }

    if ($tipo === 1) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND Tipo IN (1,4,5,8) LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $groupTlId);
        $stmt->execute();
        $exists = (bool) ($stmt->get_result()->fetch_assoc());
        $stmt->close();
        return $exists;
    }

    if (in_array($tipo, [4, 5, 8], true)) {
        return $currentId === $groupTlId;
    }

    if (in_array($tipo, [9, 10], true)) {
        if ($grupo > 0 && $grupo === $groupTlId) {
            return true;
        }
        if ($pertenece === '') {
            return false;
        }
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND Tipo IN (1,4,5,8) AND pertenece = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $groupTlId, $pertenece);
        $stmt->execute();
        $exists = (bool) ($stmt->get_result()->fetch_assoc());
        $stmt->close();
        return $exists;
    }

    return $grupo > 0 && $grupo === $groupTlId;
}

function chatGetGroupMessages(mysqli $conn, array $currentUser, int $groupTlId): array
{
    $room = chatGetVisibleGroupRoom($conn, $currentUser, $groupTlId);
    if (!$room) {
        return [];
    }

    $supportsGroupImages = chatSupportsGroupImages($conn);
    $imageJoin = $supportsGroupImages ? "
        LEFT JOIN chat_imagenes_temp i ON i.grupo_mensaje_id = m.id
    " : "";
    $imageSelect = $supportsGroupImages ? ",
        i.id AS image_id,
        i.nombre_original AS image_original_name,
        i.nombre_archivo AS image_file_name,
        i.mime_type AS image_mime_type
    " : ",
        NULL AS image_id,
        NULL AS image_original_name,
        NULL AS image_file_name,
        NULL AS image_mime_type
    ";

    $stmt = $conn->prepare("
        SELECT
            m.id,
            m.emisor_id,
            m.mensaje,
            m.enviado_en,
            u.Nombre AS emisor_nombre
            $imageSelect
        FROM chat_grupo_mensajes m
        INNER JOIN users u ON u.id = m.emisor_id
        $imageJoin
        WHERE m.grupo_tl_id = ?
        ORDER BY m.enviado_en ASC, m.id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $groupTlId);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    $currentUserId = (int) ($currentUser['id'] ?? 0);
    $lastGroupMessageId = 0;
    while ($row = $result->fetch_assoc()) {
        $groupMessageId = (int) ($row['id'] ?? 0);
        $imageId = (int) ($row['image_id'] ?? 0);
        $message = [
            'id' => $groupMessageId,
            'emisor_id' => (int) $row['emisor_id'],
            'mensaje' => trim((string) ($row['mensaje'] ?? '')),
            'enviado_en' => trim((string) ($row['enviado_en'] ?? '')),
            'estado' => 'enviado',
            'mine' => (int) ($row['emisor_id'] ?? 0) === $currentUserId,
            'sender_name' => trim((string) ($row['emisor_nombre'] ?? '')),
            'reaction_type' => 'grupo',
            'reaction_message_id' => $groupMessageId,
        ];

        if ($imageId > 0) {
            $mimeType = trim((string) ($row['image_mime_type'] ?? ''));
            $imageName = trim((string) ($row['image_original_name'] ?? 'Archivo temporal'));
            $storedFileName = trim((string) ($row['image_file_name'] ?? ''));
            $message['kind'] = 'image';
            $message['id'] = $imageId;
            $message['image_id'] = $imageId;
            $message['image_name'] = $imageName;
            $message['mime_type'] = $mimeType;
            $message['is_pdf'] = chatIsPdfAttachment($mimeType, $imageName, $storedFileName);
        } else {
            $message['kind'] = 'text';
        }

        $messages[] = $message;
        $lastGroupMessageId = $groupMessageId;
    }
    $stmt->close();

    if ($lastGroupMessageId > 0) {
        chatMarkGroupSeen($conn, $currentUserId, $groupTlId, $lastGroupMessageId);
    }

    if (!empty($messages)) {
        $reactionMap = chatGetReactionSummaries($conn, 'grupo', array_values(array_unique(array_map(
            static fn(array $message): int => (int) ($message['reaction_message_id'] ?? 0),
            $messages
        ))), $currentUserId);

        foreach ($messages as &$message) {
            $reactionMessageId = (int) ($message['reaction_message_id'] ?? 0);
            $message['reactions'] = $reactionMap[$reactionMessageId] ?? [];
        }
        unset($message);
    }

    return $messages;
}

function chatSendGroupMessage(mysqli $conn, array $currentUser, int $groupTlId, string $message): void
{
    if (!chatSupportsGroupChats($conn)) {
        throw new RuntimeException('group_chat_not_enabled');
    }

    $message = trim($message);
    if ($message === '') {
        throw new RuntimeException('empty_message');
    }

    $room = chatGetVisibleGroupRoom($conn, $currentUser, $groupTlId);
    if (!$room) {
        throw new RuntimeException('group_not_allowed');
    }

    if (!chatCanWriteGroupChats($currentUser)) {
        throw new RuntimeException('group_read_only');
    }

    $sentAt = chatNowBogotaString();
    $senderId = (int) ($currentUser['id'] ?? 0);
    $stmt = $conn->prepare("
        INSERT INTO chat_grupo_mensajes (
            grupo_tl_id, emisor_id, mensaje, enviado_en
        ) VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new RuntimeException('insert_group_message_failed');
    }

    $stmt->bind_param('iiss', $groupTlId, $senderId, $message, $sentAt);
    $stmt->execute();
    $stmt->close();
}
