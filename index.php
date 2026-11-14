<?php 
/**
 * Up : upload your file, and share it
 * author : dmeloni (corrigé et mis à jour)
 */

session_start();

function isValidName(string $titreDocument): bool {
    if (mb_strlen($titreDocument) < 3 || mb_strlen($titreDocument) > 100) {
        return false;
    }
    if (in_array(mb_strtolower($titreDocument), ['.htaccess', '.htpassword', 'robots.txt'], true)) {
        return false;
    }
    // Regex autorisant les caractères usuels de manière sécurisée
    return (bool) preg_match('#^([&a-zA-Zéèàçâäêëîïôöùûüÿœæñ\(\)0-9_\[\]\-\.: \?\=])+$#u', $titreDocument);
}

/**
 * Generate a random secure name
 */
function generateRandomName(int $car): string {
    $chaine = "abcdefghijklmnopqrstuvwxyz";
    $string = "";
    $max = strlen($chaine) - 1;
    for ($i = 0; $i < $car; $i++) {
        $string .= $chaine[random_int(0, $max)];
    }
    return $string;
}

$nbRandomCharacters = 10;
$serverMsg = '';
$folder = 'data';
$dataMappingFile = 'dataMapping.json';
$dataMappingFilePath = $folder . '/' . $dataMappingFile;

// Création automatique du dossier data s'il n'existe pas
if (!is_dir($folder)) {
    mkdir($folder, 0755, true);
}

/**
 * Upload file on server via AJAX/POST
 */
if (is_writable($folder) && isset($_FILES['myfile'])) {
    if (!isValidName($_FILES['myfile']['name'])) {
        http_response_code(400);
        exit('Invalid filename');
    }

    if ($_FILES['myfile']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(500);
        exit('Upload error code: ' . $_FILES['myfile']['error']);
    }

    // Gestion sécurisée avec verrouillage de fichier (flock)
    $fp = fopen($dataMappingFilePath, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $fileSize = filesize($dataMappingFilePath);
        $dataMapping = ($fileSize > 0) ? json_decode(fread($fp, $fileSize), true) ?? [] : [];

        do {
            $randomName = generateRandomName($nbRandomCharacters);
        } while ($randomName === $dataMappingFile || is_file($folder . '/' . $randomName));

        if (move_uploaded_file($_FILES['myfile']['tmp_name'], $folder . '/' . $randomName)) {
            $nbAvailableCopies = 1;
            if (isset($_GET['copy']) && (int)$_GET['copy'] > 0) {
                $nbAvailableCopies = (int)$_GET['copy'];
            }

            $dataMapping[$randomName] = [
                'name' => $_FILES['myfile']['name'], 
                'nb' => $nbAvailableCopies
            ];

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($dataMapping));
            
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            echo $randomName;
            exit;
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    http_response_code(500);
    exit('Server storage error');
}

/**
 * Download file 
 */
if (isset($_GET['f']) && strlen($_GET['f']) === $nbRandomCharacters) {
    $filename = basename($_GET['f']);
    $filePath = $folder . '/' . $filename;

    if (is_file($filePath)) {
        if (is_file($dataMappingFilePath)) {
            $fp = fopen($dataMappingFilePath, 'c+');
            if ($fp && flock($fp, LOCK_EX)) {
                $fileSize = filesize($dataMappingFilePath);
                $dataMapping = ($fileSize > 0) ? json_decode(fread($fp, $fileSize), true) ?? [] : [];

                if (isset($dataMapping[$filename]) && $dataMapping[$filename]['nb'] > 0) {
                    $originalName = $dataMapping[$filename]['name'];
                    $size = filesize($filePath);

                    // Headers de téléchargement sécurisés
                    header('Content-Type: application/octet-stream');
                    header('Content-Type: application/force-download; name="' . $originalName . '"');
                    header('Content-Transfer-Encoding: binary');
                    header('Content-Length: ' . $size);
                    header('Content-Disposition: attachment; filename="' . $originalName . '"');
                    header('Expires: 0');
                    header('Cache-Control: no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    
                    readfile($filePath);

                    // Décrémentation ou suppression
                    $dataMapping[$filename]['nb']--;
                    if ($dataMapping[$filename]['nb'] <= 0) {
                        @unlink($filePath);
                        unset($dataMapping[$filename]);
                    }

                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, json_encode($dataMapping));
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    exit;
                } else {
                    $serverMsg = 'File not available or expired';
                }
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    } else {
        $serverMsg = 'File not found';
    }
}

if (!(is_dir($folder) && is_writable($folder))) {
    $serverMsg = 'Data folder is not writable';
}

/*
 * Get max upload size
 */
$max_upload = (int)(ini_get('upload_max_filesize'));
$max_post = (int)(ini_get('post_max_size'));
$memory_limit = (int)(ini_get('memory_limit'));
$upload_mb = min($max_upload, $max_post, $memory_limit);
if ($upload_mb <= 0) $upload_mb = 20; // Valeur par défaut de sécurité
$upload_b = $upload_mb * 1024 * 1024;
?>
<!doctype html>
<html class="no-js" lang="fr">
<head>
	<meta charset="utf-8" />
	<title>Up - Partage de fichiers</title>
	<meta name="viewport" content="width=device-width, user-scalable=yes">
	<link rel="stylesheet" href="css/style.css">
</head>
<body>
	<div id="content">
		<section id="midle">
			<div class="upload_form_cont">
				<?php if(!empty($serverMsg)){?>
					<div class="error"><?php echo htmlspecialchars($serverMsg);?></div>
				<?php }else{?>
					<h1 id="upload-zone" class="info animated shake">Glissez vos fichiers ici :-)</h1>
					<div>Nombre de téléchargements autorisés : <input type="number" id="upload-copy" value="1" min="1"></div>
				<?php }?>
				<div>Taille maximale : <?php echo $upload_mb;?> Mo</div>
				<div id="progress"></div>
			</div>
		</section>

		<script>
		(function(){
			var dropArea = document.documentElement;
			var progress = document.getElementById('progress');
			var list = [];
			var nbDone = 0;
			var nbUploaded = 0;
			var oldColor = null;

			function initHandlers() {
				dropArea.addEventListener('drop', handleDrop, false);
				dropArea.addEventListener('dragover', handleDragOver, false);
				dropArea.addEventListener('dragleave', handleDragLeave, false);
			}

			function handleDragOver(event) {
				event.stopPropagation();
				event.preventDefault();
				if (!oldColor) oldColor = dropArea.style.color;
				dropArea.style.color = 'red';
			}

			function handleDragLeave(event) {
				event.stopPropagation();
				event.preventDefault();
				dropArea.style.color = oldColor || '#000';
			}

			function handleDrop(event) {
				dropArea.style.color = oldColor || '#000';
				event.stopPropagation();
				event.preventDefault();
				processFiles(event.dataTransfer.files);
			}

			function processFiles(filelist) {
				if (!filelist || !filelist.length || list.length) return;
				nbUploaded = filelist.length;
				for (var i = 0; i < filelist.length && i < 500; i++) {
					list.push(filelist[i]);
				}
				uploadNext();
			}

			function uploadFile(file) {
				var xhr = new XMLHttpRequest();
				var copyCount = document.getElementById('upload-copy') ? document.getElementById('upload-copy').value : 1;
				xhr.open('POST', 'index.php?copy=' + encodeURIComponent(copyCount));
				
				xhr.onload = function() {
					nbUploaded--;
					uploadNext();
				};

				if (xhr.upload) {
					xhr.upload.onprogress = function(e) {
						var done = e.loaded, total = e.total;
						var pourcentage = Math.floor(done / total * 1000) / 10;
						var progressMessage = "Fichier : " + file.name + " - Progression : " + pourcentage + "% (" + done + "/" + total + " octets)";
						var fileDiv = document.getElementById('file_' + nbDone);
						if (fileDiv) fileDiv.textContent = progressMessage;
					};
				}
				
				xhr.onreadystatechange = function() {
					if (xhr.readyState === 4) {
						var fileDiv = document.getElementById('file_' + nbDone);
						if (xhr.status === 200) {
							var progressMessage = "Fichier : " + file.name + "<br>Progression : 100%";
							var urlMessage = '<br>Lien de téléchargement : <a href="index.php?f=' + xhr.responseText + '">index.php?f=' + xhr.responseText + '</a>';
							if (fileDiv) fileDiv.innerHTML = progressMessage + urlMessage;
						} else {
							if (fileDiv) fileDiv.textContent = "Erreur lors de l'envoi du fichier : " + file.name;
						}
					}
				};

				xhr.onerror = function() {
					var fileDiv = document.getElementById('file_' + nbDone);
					if (fileDiv) fileDiv.textContent = "ATTENTION !! Erreur réseau pour : " + file.name;
					nbUploaded--;
					uploadNext();
				};

				var formData = new FormData();
				formData.append('myfile', file);
				xhr.send(formData);
			}

			function uploadNext() {
				if (list.length) {
					nbDone += 1;
					var strTemp = '<div id="file_' + nbDone + '"></div>';
					progress.innerHTML += strTemp;
					
					var nextFile = list.shift();
					var sizeMax = <?php echo (int)$upload_b;?>;
					
					if (nextFile.size >= sizeMax) {
						var fileDiv = document.getElementById('file_' + nbDone);
						fileDiv.textContent = "ATTENTION !! Le fichier " + nextFile.name + " est trop volumineux !";
						uploadNext();
					} else {
						uploadFile(nextFile);
					}
				}
			}

			initHandlers();
		})();
		</script>
	</div>
</body>
</html>
