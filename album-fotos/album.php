<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';

// Processar upload de imagem
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['photo'])) {
    $file = $_FILES['photo'];
    
    // Validações
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Erro no upload da imagem.';
        $messageType = 'error';
    } elseif ($file['size'] > MAX_FILE_SIZE) {
        $message = 'Arquivo muito grande. Máximo: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB';
        $messageType = 'error';
    } else {
        $fileInfo = pathinfo($file['name']);
        $extension = strtolower($fileInfo['extension']);
        
        if (!in_array($extension, ALLOWED_TYPES)) {
            $message = 'Tipo de arquivo não permitido. Use: ' . implode(', ', ALLOWED_TYPES);
            $messageType = 'error';
        } else {
            // Criar diretório do usuário
            $userDir = createUserUploadDir($_SESSION['user_id']);
            
            // Gerar nome único para o arquivo
            $filename = uniqid() . '_' . time() . '.' . $extension;
            $filepath = $userDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Salvar no banco de dados
                try {
                    $pdo = getConnection();
                    $stmt = $pdo->prepare("INSERT INTO photos (user_id, filename, original_name, file_size) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $filename, $file['name'], $file['size']]);
                    
                    $message = 'Imagem enviada com sucesso!';
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = 'Erro ao salvar no banco: ' . $e->getMessage();
                    $messageType = 'error';
                    // Remover arquivo se erro no banco
                    unlink($filepath);
                }
            } else {
                $message = 'Erro ao salvar arquivo no servidor.';
                $messageType = 'error';
            }
        }
    }
}

// Buscar fotos do usuário
try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM photos WHERE user_id = ? ORDER BY upload_datetime DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Erro ao carregar fotos: ' . $e->getMessage();
    $messageType = 'error';
    $photos = [];
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Álbum - <?php echo htmlspecialchars($_SESSION['username']); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">📸 Meu Álbum</div>
                <div class="nav-links">
                    <span class="user-info">Olá, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                    <a href="logout.php">Sair</a>
                </div>
            </div>
        </div>

        <!-- Upload de fotos -->
        <div class="card">
            <h3 style="margin-bottom: 1.5rem;">Enviar Nova Foto</h3>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="upload-area" onclick="document.getElementById('photoInput').click()">
                    <p style="font-size: 1.2rem; margin-bottom: 0.5rem;">📷 Clique para selecionar uma foto</p>
                    <p style="color: #666; font-size: 0.9rem;">
                        Formatos aceitos: JPG, PNG, GIF | Tamanho máximo: <?php echo MAX_FILE_SIZE / 1024 / 1024; ?>MB
                    </p>
                    <input type="file" id="photoInput" name="photo" accept="image/*" style="display: none;" onchange="previewImage(this)">
                </div>
                
                <div id="preview" style="margin-top: 1rem; display: none;">
                    <img id="previewImg" style="max-width: 200px; max-height: 200px; border-radius: 8px;">
                    <p id="fileName" style="margin-top: 0.5rem; font-weight: 500;"></p>
                </div>
                
                <button type="submit" class="btn" style="margin-top: 1rem;" id="uploadBtn" disabled>
                    Enviar Foto
                </button>
            </form>
        </div>

        <!-- Galeria de fotos -->
        <div class="card">
            <h3 style="margin-bottom: 1.5rem;">
                Minhas Fotos (<?php echo count($photos); ?>)
            </h3>
            
            <?php if (empty($photos)): ?>
                <div class="empty-state">
                    <h3>Nenhuma foto ainda</h3>
                    <p>Envie sua primeira foto usando o formulário acima!</p>
                </div>
            <?php else: ?>
                <div class="photos-grid">
                    <?php foreach ($photos as $photo): ?>
                        <div class="photo-item">
                            <img src="uploads/<?php echo $_SESSION['user_id']; ?>/<?php echo htmlspecialchars($photo['filename']); ?>" 
                                 alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                                 loading="lazy">
                            <div class="photo-info">
                                <div class="photo-date">
                                    <?php echo date('d/m/Y H:i', strtotime($photo['upload_datetime'])); ?>
                                </div>
                                <div class="photo-name">
                                    <?php echo htmlspecialchars($photo['original_name']); ?>
                                </div>
                                <div class="photo-size">
                                    <?php echo formatFileSize($photo['file_size']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function previewImage(input) {
            const preview = document.getElementById('preview');
            const previewImg = document.getElementById('previewImg');
            const fileName = document.getElementById('fileName');
            const uploadBtn = document.getElementById('uploadBtn');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validar tamanho
                if (file.size > <?php echo MAX_FILE_SIZE; ?>) {
                    alert('Arquivo muito grande! Máximo: <?php echo MAX_FILE_SIZE / 1024 / 1024; ?>MB');
                    input.value = '';
                    preview.style.display = 'none';
                    uploadBtn.disabled = true;
                    return;
                }
                
                // Validar tipo
                const allowedTypes = <?php echo json_encode(ALLOWED_TYPES); ?>;
                const fileExtension = file.name.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(fileExtension)) {
                    alert('Tipo de arquivo não permitido! Use: ' + allowedTypes.join(', '));
                    input.value = '';
                    preview.style.display = 'none';
                    uploadBtn.disabled = true;
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    fileName.textContent = file.name;
                    preview.style.display = 'block';
                    uploadBtn.disabled = false;
                };
                reader.readAsDataURL(file);
            }
        }
        
        // Drag and drop
        const uploadArea = document.querySelector('.upload-area');
        
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('photoInput').files = files;
                previewImage(document.getElementById('photoInput'));
            }
        });
    </script>
</body>
</html>