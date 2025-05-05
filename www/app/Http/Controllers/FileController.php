<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Gif\Exceptions\NotReadableException;
// Предполагаем использование ImagickDriver для широкой поддержки форматов, включая взаимодействие с PDF через Ghostscript
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Log;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Spatie\PdfToImage\Pdf;
use Symfony\Component\Process\Process; 
use Illuminate\View\View;

class FileController extends Controller
{
    /**
     * Загружает и обрабатывает файл изображения или PDF.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        // Валидирует данные запроса.
        $request->validate([
            'file' => 'required|file',
            'width' => 'required|integer|min:1',
            'height' => 'required|integer|min:1',
            'size' => 'required|integer|min:1', // Размер (используется как качество для изображений, может быть использован для настроек Ghostscript)
            'extension' => 'required|string|in:jpg,jpeg,png,webp,gif,bmp,tif,tiff,avif,heic,jp2,pdf',
        ]);

        $file = $request->file('file');
        $targetWidth = (int) $request->input('width');
        $targetHeight = (int) $request->input('height');
        $targetSize = (int) $request->input('size'); // Используем как параметр качества/настроек
        $targetExtension = strtolower($request->input('extension')); // Желаемое расширение для изображения (или 'pdf' для PDF)

        // Переменные для путей и ID файлов
        $originalFileId = null;
        $originalFilePath = null;
        $minifiedPdfId = null;
        $minifiedPdfFilePath = null;
        $previewImageId = null;
        $previewImageFilePath = null;

        try {
            // 1. Сохраняем оригинальный файл
            $originalFileId = Str::uuid();
            $originalFileName = $originalFileId . '-' . $file->getClientOriginalName();
            $originalFileExtension = strtolower($file->getClientOriginalExtension());
            $originalFilePath = Storage::disk('public')->putFileAs('uploads/original', $file, $originalFileId . '.' . $originalFileExtension);

            if (!$originalFilePath) {
                throw new \Exception('Не удалось сохранить оригинальный файл.');
            }

            $originalFileType = $file->getClientMimeType();

            // Переменная для хранения информации о созданных файлах для ответа API
            $convertedOutput = null;

            // 2. Обрабатываем файл в зависимости от типа
            if (in_array($originalFileType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/tiff', 'image/tif', 'image/avif', 'image/heic', 'image/jp2'])) {
                // Обработка изображений (остается почти как было - один выходной файл)

                $imageManager = new ImageManager(new ImagickDriver()); // Используем ImagickDriver
                $image = $imageManager->read(Storage::disk('public')->path($originalFilePath));

                $image->resize($targetWidth, $targetHeight);

                $convertedFileId = Str::uuid();
                $convertedFileName = $convertedFileId . '.' . $targetExtension;
                $convertedFilePath = 'uploads/converted/' . $convertedFileName;

                // Конвертация изображения
                 switch ($targetExtension) {
                    case 'jpg':
                    case 'jpeg':
                        $image->toJpeg($targetSize); // Размер как качество (0-100)
                        break;
                    case 'png':
                        $image->toPng(); // Уровень сжатия (0-9)
                        break;
                    case 'webp':
                        $image->toWebp($targetSize);  // Размер как качество (0-100)
                        break;
                    case 'gif':
                        $image->toGif();
                        break;
                    case 'bmp':
                        $image->toBmp();
                        break;
                    case 'tif':
                    case 'tiff':
                        $image->toTiff();
                        break;
                    case 'avif':
                        $image->toAvif($targetSize); // Качество (0-100)
                        break;
                    case 'heic':
                        $image->toHeic($targetSize); // Качество (0-100)
                        break;
                    case 'jp2':
                        $image->toJp2($targetSize); // Качество (0-100)
                        break;
                    default:
                        throw new \Exception('Неподдерживаемое расширение изображения для конвертации.');
                }

                Storage::disk('public')->put($convertedFilePath, $image->encode());

                if (!Storage::disk('public')->exists($convertedFilePath)) {
                    throw new \Exception('Не удалось сохранить конвертированное изображение.');
                }

                // Формируем выходные данные для изображения
                $convertedOutput = [
                    'id' => $convertedFileId,
                    'name' => $convertedFileName,
                    'path' => Storage::url($convertedFilePath),
                    'extension' => $targetExtension,
                ];

                // Сохраняем информацию о конвертированном файле в БД.
                $convertedFileModel = new File();
                $convertedFileModel->uuid = $convertedFileId;
                $convertedFileModel->original_file_uuid = $originalFileId;
                $convertedFileModel->file_name = $convertedFileName;
                $convertedFileModel->file_path = $convertedFilePath;
                $convertedFileModel->target_width = $targetWidth;
                $convertedFileModel->target_height = $targetHeight;
                $convertedFileModel->extension = $targetExtension;
                $convertedFileModel->size = Storage::disk('public')->size($convertedFilePath);
                $convertedFileModel->save();


            } elseif ($originalFileType === 'application/pdf') {
                // Обработка PDF (создаем минифицированный PDF и превью-изображение)

                // 2a. Создаем минифицированный PDF с помощью Ghostscript
                $minifiedPdfId = Str::uuid();
                $minifiedPdfFileName = $minifiedPdfId . '.pdf';
                $minifiedPdfFilePath = 'uploads/converted/' . $minifiedPdfFileName;
                $fullOriginalPath = Storage::disk('public')->path($originalFilePath);
                $fullMinifiedPath = Storage::disk('public')->path($minifiedPdfFilePath);

                // Настройка качества для Ghostscript. /ebook часто используется для хорошего сжатия.
                // $targetSize из запроса можно использовать для выбора других настроек, если нужно.
                $pdfSettings = '/ebook'; // Default setting for optimization
                 if ($targetSize >= 0 && $targetSize <= 30) { // Пример использования targetSize для выбора настроек
                     $pdfSettings = '/screen';
                 } elseif ($targetSize > 30 && $targetSize <= 70) {
                     $pdfSettings = '/ebook';
                 } elseif ($targetSize > 70) {
                     $pdfSettings = '/printer';
                 }


                // Формируем команду Ghostscript. Убедитесь, что 'gs' доступен в PATH.
                // Используем -dBatch -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.4
                // -dPDFSETTINGS=/ebook для оптимизации
                // -sOutputFile=... - путь куда сохранять
                // ... путь к входному файлу

                $process = new Process([
                    'gs',
                    '-sDEVICE=pdfwrite',
                    '-dCompatibilityLevel=1.4', // Совместимость
                    '-dPDFSETTINGS=' . $pdfSettings, // Настройки сжатия/оптимизации
                    '-dNOPAUSE',
                    '-dBATCH',
                    '-sOutputFile=' . $fullMinifiedPath,
                    $fullOriginalPath, // Входной файл
                ]);

                $process->run();

                // Проверяем результат выполнения команды
                if (!$process->isSuccessful()) {
                     // Логируем ошибку Ghostscript для отладки
                    \Log::error('Ghostscript Error: ' . $process->getErrorOutput());
                    throw new \Exception('Ошибка при минификации PDF: ' . $process->getErrorOutput());
                }

                if (!Storage::disk('public')->exists($minifiedPdfFilePath)) {
                    throw new \Exception('Не удалось сохранить минифицированный PDF.');
                }

                 // Сохраняем информацию о минифицированном файле PDF в БД.
                $minifiedPdfModel = new File();
                $minifiedPdfModel->uuid = $minifiedPdfId; // Это UUID для записи минифицированного PDF
                $minifiedPdfModel->original_file_uuid = $originalFileId; // Ссылка на оригинальный файл
                $minifiedPdfModel->file_name = $minifiedPdfFileName;
                $minifiedPdfModel->file_path = $minifiedPdfFilePath;
                $minifiedPdfModel->target_width = null; // Минифицированный PDF не имеет целевых размеров в этом контексте
                $minifiedPdfModel->target_height = null;
                $minifiedPdfModel->extension = 'pdf'; // Расширение минифицированного файла
                $minifiedPdfModel->size = Storage::disk('public')->size($minifiedPdfFilePath);
                $minifiedPdfModel->save();


                // 2b. Создаем превью-изображение из оригинального PDF (используем оригинальный файл для лучшего качества)
                $previewImageId = Str::uuid();
                $previewImageFileName = $previewImageId . '.jpg'; // Превью всегда JPG
                $previewImageFilePath = 'uploads/converted/' . $previewImageFileName;

                try {
                    // Используем оригинальный PDF для создания превью
                    $pdf = new Pdf($fullOriginalPath);
                    // Можно задать разрешение превью, если нужно
                    // $pdf->setResolution(300);
                    $pdf->saveImage(Storage::disk('public')->path($previewImageFilePath));

                } catch (\Exception $e) {
                     // Логируем ошибку генерации превью
                    \Log::error('PDF Preview Error: ' . $e->getMessage());
                    throw new \Exception('Ошибка при генерации превью PDF: ' . $e->getMessage());
                }

                if (!Storage::disk('public')->exists($previewImageFilePath)) {
                    throw new \Exception('Не удалось сохранить файл превью PDF.');
                }

                 // Сохраняем информацию о файле превью в БД.
                $previewImageModel = new File();
                $previewImageModel->uuid = $previewImageId; // Это UUID для записи превью
                $previewImageModel->original_file_uuid = $originalFileId; // Ссылка на оригинальный файл
                $previewImageModel->file_name = $previewImageFileName;
                $previewImageModel->file_path = $previewImageFilePath;
                 // Размеры превью можно добавить, если нужно
                $previewImageModel->target_width = null;
                $previewImageModel->target_height = null;
                $previewImageModel->extension = 'jpg'; // Расширение превью
                $previewImageModel->size = Storage::disk('public')->size($previewImageFilePath); // Добавьте эту строку
                $previewImageModel->save();


                // Формируем выходные данные для PDF (минифицированный PDF + превью)
                $convertedOutput = [
                    'minified_pdf' => [ // Информация о минифицированном PDF
                        'id' => $minifiedPdfId,
                        'name' => $minifiedPdfFileName,
                        'path' => Storage::url($minifiedPdfFilePath),
                        'extension' => 'pdf',
                    ],
                    'preview_image' => [ // Информация о превью (изображении)
                        'id' => $previewImageId,
                        'name' => $previewImageFileName,
                        'path' => Storage::url($previewImageFilePath),
                        'extension' => 'jpg',
                    ],
                ];


            } else {
                // Если тип файла не поддерживается
                throw new \Exception('Тип файла не поддерживается.');
            }

            // 3. Сохраняем информацию об оригинальном файле в базе данных
            // (Оригинальный файл сохраняется для отслеживания, но не возвращается в ответе)
            $originalFileModel = new File();
            $originalFileModel->uuid = $originalFileId;
            $originalFileModel->original_file_uuid = null; // Это корневой файл
            $originalFileModel->file_name = $originalFileName;
            $originalFileModel->file_path = $originalFilePath;
            $originalFileModel->target_width = null;
            $originalFileModel->target_height = null;
            $originalFileModel->extension = $originalFileExtension;
            $originalFileModel->size = $file->getSize();
            $originalFileModel->save();


            // 4. Возвращаем успешный ответ
            return response()->json([
                'original_file_id' => $originalFileId, // ID оригинального файла (для связи)
                'converted_output' => $convertedOutput, // Объект с информацией о созданных файлах
            ], 200);

        } catch (NotReadableException $e) {
            // Ошибка при чтении файла
            $this->cleanupFiles([$originalFilePath, $minifiedPdfFilePath, $previewImageFilePath]);
            return response()->json(['error' => 'Ошибка при чтении файла: ' . $e->getMessage()], 400);
        } catch (FileException $e) {
             // Ошибка файловой системы
            $this->cleanupFiles([$originalFilePath, $minifiedPdfFilePath, $previewImageFilePath]);
            return response()->json(['error' => 'Ошибка файла: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            // Любые другие unexpected ошибки (включая ошибки Ghostscript или Spatie/PdfToImage)
            $this->cleanupFiles([$originalFilePath, $minifiedPdfFilePath, $previewImageFilePath]);
             // В продакшене лучше не выводить детальное сообщение $e->getMessage() для общих ошибок
            return response()->json(['error' => 'Произошла ошибка при обработке: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Вспомогательная функция для удаления файлов в случае ошибки.
     */
    protected function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * Удаляет файл и связанные с ним файлы/записи.
     * Этот метод используется AJAX запросом с фронтенда.
     *
     * @param  string  $uuid  UUID файла для удаления (оригинального или конвертированного/превью).
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(string $uuid): \Illuminate\Http\JsonResponse
    {
        // Находим файл по UUID. Используем find() вместо firstOrFail() для мягкой обработки 404
        $fileToDelete = File::where('uuid', $uuid)->first();

        if (!$fileToDelete) {
            return response()->json(['message' => 'Файл не найден.'], 404);
        }

        // Определяем, что удалять:
        // Если original_file_uuid NULL, значит это оригинальный файл.
        // Если original_file_uuid НЕ NULL, значит это конвертированный файл или превью.

        if ($fileToDelete->original_file_uuid === null) {
            // Удаляется ОРИГИНАЛЬНЫЙ файл. Удаляем его и все связанные с ним конвертированные/превью файлы.

            // Находим все связанные записи (сам оригинал и все, где original_file_uuid совпадает)
            $relatedFiles = File::where('uuid', $uuid)
                                ->orWhere('original_file_uuid', $uuid)
                                ->get();

            $deletedPaths = [];
            foreach ($relatedFiles as $relatedFile) {
                // Удаляем физический файл
                if ($relatedFile->file_path && Storage::disk('public')->exists($relatedFile->file_path)) {
                     try {
                         Storage::disk('public')->delete($relatedFile->file_path);
                         $deletedPaths[] = $relatedFile->file_path;
                     } catch (\Exception $e) {
                          Log::error("Failed to delete physical file during cleanup: Path={$relatedFile->file_path}, Error: {$e->getMessage()}");
                     }
                }
                // Удаляем запись из БД
                $relatedFile->delete();
            }

             Log::info("Original file and related files deleted: UUID={$uuid}, Paths=".implode(', ', $deletedPaths));


        } else {
            // Удаляется КОНВЕРТИРОВАННЫЙ файл или ПРЕВЬЮ. Удаляем только его.

            // Удаляем физический файл
            $deletedPath = $fileToDelete->file_path;
            $physicalFileDeleted = false;
            if ($deletedPath && Storage::disk('public')->exists($deletedPath)) {
                try {
                    Storage::disk('public')->delete($deletedPath);
                    $physicalFileDeleted = true;
                    Log::info("Converted file deleted: UUID={$uuid}, Path={$deletedPath}");
                } catch (\Exception $e) {
                    Log::error("Failed to delete physical converted file: Path={$deletedPath}, Error: {$e->getMessage()}");
                }
            } else {
                Log::warning("Converted file record found, but physical file not found for deletion: UUID={$uuid}, Path={$deletedPath}");
                 $physicalFileDeleted = true; // Считаем успешным, если файла уже нет
            }

             // Удаляем запись из БД только если физический файл удален или не существовал
            if ($physicalFileDeleted) {
                $fileToDelete->delete();
                return response()->json(['message' => 'Файл успешно удален.']);
            } else {
                // Если не удалось удалить физический файл
                return response()->json(['message' => 'Не удалось удалить физический файл, запись в базе данных осталась.'], 500);
            }

            // При удалении конвертированного файла, оригинальный файл и другие конвертированные остаются.
        }

        return response()->json(['message' => 'Файл успешно удален и связанные файлы (если были).']);
    }

    /**
     * Вспомогательная функция для форматирования размера файла в читаемый вид.
     * Помещена как protected метод контроллера.
     *
     * @param int|float|null $bytes     Количество байт.
     * @param int            $precision Количество знаков после запятой.
     * @return string
     */
    protected function formatBytes($bytes, $precision = 2): string
    {
        if ($bytes === null || !is_numeric($bytes) || $bytes < 0) {
            return '-';
        }

        if ($bytes === 0) {
            return '0 Bytes';
        }

        $k = 1024;
        $precision = max($precision, 0);
        $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

        $i = floor(log($bytes, $k));

        if ($i >= count($sizes)) {
            $i = count($sizes) - 1;
        }

        return round($bytes / ($k ** $i), $precision) . ' ' . $sizes[$i];
    }

    /**
     * Отображает список файлов с поиском, сортировкой и пагинацией.
     * Этот метод используется веб-роутом.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        // ... (получение параметров поиска и сортировки) ...
        $search = $request->get('search');
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $perPage = 15; // Количество элементов на странице

        $query = File::query();

        if ($search) {
            $query->where('file_name', 'like', '%' . $search . '%');
        }

        $allowedSortColumns = ['uuid', 'file_name', 'created_at', 'size', 'original_file_uuid'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'created_at';
        }
        $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) ? strtolower($sortDirection) : 'desc';

        $query->orderBy($sortBy, $sortDirection);

        // Получаем данные с пагинацией и преобразуем их
        $files = $query->paginate($perPage)->through(function ($file) {
            // Преобразуем модель File в массив
            $fileArray = $file->toArray();

            // Добавляем отформатированный размер файла
            $fileArray['formatted_size'] = $this->formatBytes($file->size);

            // ДОБАВЛЯЕМ ПУБЛИЧНЫЙ URL ФАЙЛА
            // Storage::url() генерирует URL для диска 'public'
            // Убедитесь, что 'public' диск настроен правильно и есть симлинк public/storage
            $fileArray['public_url'] = $file->file_path ? Storage::disk('public')->url($file->file_path) : null;


            return $fileArray; // Возвращаем преобразованный элемент (массив с добавленными ключами)
        });


        // Возвращаем Blade шаблон, передавая данные
        return view('files.index', [
            'files' => $files, // Пагинированные файлы (теперь с formatted_size и public_url)
            'search' => $search,
            'sortBy' => $sortBy,
            'sortDirection' => $sortDirection,
        ]);
    }
}