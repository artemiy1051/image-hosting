<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Список файлов</title>
    {{-- Добавляем CSRF токен для AJAX запросов удаления --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Стили: Можно перенести эти стили в отдельный CSS файл --}}
    <style>
        body { font-family: sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .file-list-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        h1 { margin-top: 0; color: #333; }
        .controls {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px; /* Уменьшаем пространство */
            flex-wrap: wrap; /* Перенос на новую строку на узких экранах */
        }
        .search-input {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            min-width: 200px;
            box-sizing: border-box; /* Учитываем padding и border в ширине */
        }
         .controls button {
             padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background-color: #e0e0e0; /* Более нейтральный цвет кнопки */
            cursor: pointer;
            transition: background-color 0.2s ease;
         }
         .controls button:hover {
             background-color: #d5d5d5;
         }
         .controls button[type="submit"] {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
         }
         .controls button[type="submit"]:hover {
            background-color: #0056b3;
         }
         .controls button[onclick*="window.location"] { /* Сброс */
             background-color: #6c757d;
             color: white;
             border-color: #6c757d;
         }
         .controls button[onclick*="window.location"]:hover {
             background-color: #5a6268;
         }

        .files-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .files-table th, .files-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .files-table th {
            background-color: #f2f2f2;
        }
        .files-table th a {
            text-decoration: none;
            color: inherit;
            display: flex; /* Используем flexbox */
            align-items: center; /* Выравнивание по центру по вертикали */
             justify-content: space-between; /* Размещаем текст и стрелку */
             width: 100%; /* Растягиваем ссылку */
             box-sizing: border-box; /* Учитываем padding в ширине */
        }
         .sort-arrow {
             margin-left: 5px; /* Отступ для стрелки */
             font-size: 0.8em; /* Размер стрелки */
         }
        .files-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
         .files-table td {
             word-break: break-word; /* Перенос длинных слов */
         }
         .files-table td:nth-child(1), /* ID файла */
         .files-table td:nth-child(5)  /* ID исходного файла */
         {
             font-size: 0.9em; /* Чуть меньше шрифт для ID */
             max-width: 150px; /* Ограничиваем ширину колонок с ID */
             overflow: hidden; /* Скрываем выходящий текст */
             text-overflow: ellipsis; /* Добавляем многоточие */
         }
          .files-table th:nth-child(1),
           .files-table th:nth-child(5) {
              max-width: 150px; /* Ограничиваем ширину заголовков ID */
           }


        .delete-button {
            padding: 5px 10px;
            background-color: #dc3545; /* Красный */
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .delete-button:hover {
            background-color: #c82333; /* Темнее красный */
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            list-style: none;
            padding: 0;
        }
         .pagination li {
             margin: 0 5px;
         }
         .pagination li a, .pagination li span {
             padding: 8px 12px;
             border: 1px solid #ccc;
             border-radius: 4px;
             text-decoration: none;
             color: #007bff;
             transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
         }
          .pagination li span {
              background-color: #e9ecef; /* Серый */
              color: #495057; /* Темно-серый */
              border-color: #dee2e6;
              cursor: default;
          }
          .pagination li.active span {
              background-color: #007bff;
              color: white;
              border-color: #007bff;
          }
           .pagination li a:hover {
               background-color: #e9ecef;
           }
           .pagination li.disabled span {
               color: #6c757d;
               pointer-events: none;
               cursor: default;
               background-color: #e9ecef;
               border-color: #dee2e6;
           }


        .no-files {
            margin-top: 20px;
            text-align: center;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="file-list-container">
        <h1>Список файлов</h1>

        {{-- Форма для поиска --}}
        <form action="{{ url('/files') }}" method="GET" class="controls">
            <input
                type="text"
                name="search"
                placeholder="Поиск по названию файла..."
                value="{{ request('search') }}" {{-- Сохраняем текущий поисковый запрос --}}
                class="search-input"
            >
            {{-- Скрытые поля для сохранения текущей сортировки при поиске --}}
            <input type="hidden" name="sort_by" value="{{ $sortBy }}">
            <input type="hidden" name="sort_direction" value="{{ $sortDirection }}">

            <button type="submit">Найти</button>
             {{-- Кнопка сброса поиска и сортировки, если есть активные параметры --}}
            @if(request('search') || $sortBy != 'created_at' || $sortDirection != 'desc')
                 <button type="button" onclick="window.location.href='{{ url('/files') }}'">Сброс</button>
            @endif
        </form>


        @if($files->isEmpty())
            <p class="no-files">Нет загруженных файлов.</p>
        @else
            <table class="files-table">
                <thead>
                    <tr>
                        {{-- Заголовки с ссылками для сортировки --}}
                        <th>
                            {{-- Ссылка для сортировки по UUID --}}
                            <a href="{{ url('/files') }}?sort_by=uuid&sort_direction={{ $sortBy == 'uuid' && $sortDirection == 'asc' ? 'desc' : 'asc' }}{{ request('search') ? '&search=' . request('search') : '' }}">
                                Идентификатор файла
                                @if($sortBy == 'uuid')
                                    <span class="sort-arrow">{{ $sortDirection == 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th>
                            {{-- Ссылка для сортировки по названию --}}
                            <a href="{{ url('/files') }}?sort_by=file_name&sort_direction={{ $sortBy == 'file_name' && $sortDirection == 'asc' ? 'desc' : 'asc' }}{{ request('search') ? '&search=' . request('search') : '' }}">
                                Название
                                @if($sortBy == 'file_name')
                                    <span class="sort-arrow">{{ $sortDirection == 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th>
                            {{-- Ссылка для сортировки по дате загрузки --}}
                            <a href="{{ url('/files') }}?sort_by=created_at&sort_direction={{ $sortBy == 'created_at' && $sortDirection == 'asc' ? 'desc' : 'asc' }}{{ request('search') ? '&search=' . request('search') : '' }}">
                                Дата загрузки
                                @if($sortBy == 'created_at')
                                    <span class="sort-arrow">{{ $sortDirection == 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                         <th>
                             {{-- Ссылка для сортировки по размеру --}}
                            <a href="{{ url('/files') }}?sort_by=size&sort_direction={{ $sortBy == 'size' && $sortDirection == 'asc' ? 'desc' : 'asc' }}{{ request('search') ? '&search=' . request('search') : '' }}">
                                Размер файла
                                @if($sortBy == 'size')
                                    <span class="sort-arrow">{{ $sortDirection == 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th>Идентификатор исходного файла</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Перебираем файлы текущей страницы --}}
                    @foreach ($files as $file)
                        {{-- В $file теперь массив, потому что мы использовали ->through() --}}
                        <tr data-uuid="{{ $file['uuid'] }}"> {{-- Получаем UUID из массива --}}
                            <td>{{ $file['uuid'] }}</td>
                            <td>
                                {{-- Ссылка на файл --}}
                                @if($file['public_url'])
                                    <a href="{{ $file['public_url'] }}" target="_blank">{{ $file['file_name'] }}</a>
                                @else
                                    {{ $file['file_name'] }} {{-- Если по какой-то причине URL не сгенерирован, просто выводим имя --}}
                                @endif
                            </td>
                            {{-- Дата приходит уже в виде строки, можно отформатировать в JS или здесь, если нужна другая локаль --}}
                            <td>{{ \Carbon\Carbon::parse($file['created_at'])->format('d.m.Y H:i') }}</td>
                            <td>{{ $file['formatted_size'] ?? '-' }}</td> {{-- Используем отформатированный размер --}}
                            <td>{{ $file['original_file_uuid'] ?? '-' }}</td> {{-- Получаем из массива --}}
                            <td>
                                {{-- Кнопка удаления --}}
                                <button class="delete-button" data-uuid="{{ $file['uuid'] }}">Удалить</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Отображение ссылок пагинации --}}
            {{ $files->appends(['search' => request('search'), 'sort_by' => $sortBy, 'sort_direction' => $sortDirection])->links('pagination::bootstrap-4') }}

        @endif
    </div>

    {{-- Встраиваем JavaScript для AJAX удаления --}}
    {{-- Можно вынести этот скрипт в отдельный файл JS --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Находим все кнопки удаления
            const deleteButtons = document.querySelectorAll('.delete-button');

            // Добавляем обработчик клика для каждой кнопки
            deleteButtons.forEach(button => {
                button.addEventListener('click', function () {
                    // Получаем UUID файла из data-атрибута кнопки
                    const fileUuid = this.dataset.uuid;

                    // Запрашиваем подтверждение у пользователя
                    if (confirm('Вы уверены, что хотите удалить этот файл?')) {
                        // Получаем CSRF токен из мета-тега в заголовке страницы
                        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                        // Выполняем AJAX DELETE запрос к API
                        fetch(`/api/files/${fileUuid}`, { // URL к вашему API роуту удаления
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken, // Добавляем CSRF токен в заголовок для безопасности
                                'Content-Type': 'application/json', // Указываем, что отправляем JSON
                                'Accept': 'application/json' // Указываем, что ожидаем JSON в ответ
                            }
                        })
                        .then(response => {
                            // Проверяем статус ответа HTTP
                            if (!response.ok) {
                                // Если статус не 2xx, парсим ошибку из тела ответа
                                return response.json().then(err => {
                                     console.error('API error response:', err); // Логируем детальный ответ API
                                     throw new Error(err.message || `Ошибка сервера: ${response.status}`); // Выбрасываем ошибку
                                });
                            }
                            // Если успешно, парсим JSON ответ
                            return response.json();
                        })
                        .then(data => {
                            // Файл успешно удален на сервере, обрабатываем успех на фронтенде
                            alert(data.message || 'Файл успешно удален.'); // Показываем сообщение из ответа API

                            // Находим строку таблицы, соответствующую удаленному файлу, и удаляем ее из DOM
                            const row = document.querySelector(`tr[data-uuid="${fileUuid}"]`);
                            if (row) {
                                row.remove();
                            }

                            // Опционально: если пагинация и сортировка важны, может потребоваться
                            // перезагрузить страницу или выполнить AJAX запрос для обновления списка
                            // window.location.reload();

                        })
                        .catch(error => {
                            // Обработка ошибок сети или ошибок, выброшенных из первого .then()
                            console.error('Ошибка при удалении файла:', error);
                            alert('Не удалось удалить файл: ' + (error.message || 'Произошла неизвестная ошибка.'));
                        });
                    }
                });
            });
        });
    </script>

</body>
</html>