# Dokumenty sprzedaży - rozwiązanie zadania rekrutacyjnego

## Konfiguracja

```bash
docker compose up -d   # baza Postgres z compose.yaml
composer install
composer test           # tworzy bazę app_test (jeśli nie istnieje), uruchamia migracje, odpala testy
```

`composer test` uruchamia po kolei:
* `doctrine:database:create --env=test --if-not-exists` (tworzy drugą  bazę, `app_test`, w tym samym kontenerze Postgres co `dev`),
* `doctrine:migrations:migrate --env=test` (prawdziwe migracje, te same co w `dev`/`prod`)
* `phpunit`.

Testy, `dev` i `prod` korzystają z jednego, tego samego kontenera Postgres z `compose.yaml`.

Projekt używa `Symfony\Component\HttpKernel\Log\Logger` (Monolog przy tej skali zbędny) z jawnym `$minLevel: debug`.

Katalog `var/log/` jest w `.gitignore` z wyjątkiem dla `var/log/.gitkeep` - po świeżym `git clone` katalog będzie 
istniał, zanim logger spróbuje otworzyć plik.

## Problem 1 - zatwierdzenie „wygląda na nieudane”, choć zostało zapisane w bazie

### Diagnoza
**Objaw:**
Test `ApproveSalesDocumentTest::testApprovalDoesNotFailTheCallerWhenTheNotificationChannelFails` 
zakończył się błędem `RuntimeException`, mimo że dokument został już zatwierdzony.

**Przyczyna:**
`ApproveSalesDocumentHandler` poprawnie obsługiwał dokument, po czym wysyłał notyfikację, która mogła rzucić wyjątkiem.
Zatem operacja udana, ale proces nie zakończył się sukcesem. Wyjątek docierał do kontrolera, gdzie był publikowany
ze statusem 500, a klient otrzymywał informację o "niepowodzeniu" operacji, która faktycznie kończyła się sukcesem.

## Rozwiązanie
Odizolowanie powiadomień w metodzie `notifySafely()`, która przechwytuje i loguje wszelkie wyjątki `Throwable`, 
zamiast pozwalać im wydostać się z handlera.
O powodzeniu lub niepowodzeniu komendy decyduje wyłącznie wynik zapisu (transakcji). Powiadomienia realizowane są 
w modelu „best-effort” (próba wykonania bez gwarancji sukcesu).

## Problem 2 - kontroler zwraca odpowiedź ze statusem `500` niezależnie od powodu

### Diagnoza
Metoda `SalesDocumentController::approve()` bezwarunkowo przechwytywała `Throwable` i zwracała surową treść wyjątku 
wraz ze statusem `500`, niezależnie od problemu:
* brak zasobu = „not found”,
* nieprawidłowe przejście stanu = „invalid state transition”,
* czy rzeczywisty błąd serwera.
Kontroler pomijał repozytorium i wykonywał surowe zapytanie SQL `SELECT` bezpośrednio na połączeniu z bazą.

### Rozwiązanie
- wprowadzenie 2 nowych wyjątków dziedziczących po `RuntimeException`, o nazwach jasno określających ich przeznaczenie,
 `SalesDocumentNotFoundException` oraz `InvalidSalesDocumentStateException`.
- `ApproveSalesDocumentHandler` oraz (nowy) `RejectSalesDocumentHandler` zgłaszają je zamiast ogólnego `RuntimeException`.
- kontroler wyodrębnia wyjątek źródłowy za pomocą `HandlerFailedException::getPrevious()` (synchroniczny
  transport komponentu Messenger opakowuje wyjątek zgłoszony przez handler, ustawiając jako wyjątek poprzedni i mapuje:
  - `SalesDocumentNotFoundException` -> `404`,
  - `InvalidSalesDocumentStateException` -> `409`,
  - wszystkie inne -> ogólny błąd `500` (bez ujawniania surowej treści wyjątku dla błędów rzeczywiście nieoczekiwanych).
- kontroler korzysta teraz z `SalesDocumentRepository::getOrFail()` zamiast ręcznie pisanego zapytania SQL.
- dodane testy na różne kody zwracanych statusów.

Dodatkowo `create()` jest objęty tym samym mapowaniem wyjątków co `approve()`, a gałąź "nieoczekiwany błąd -> 500" 
loguje również oryginalny wyjątek (`LoggerInterface::error()`) - bez tego zabiegu realne błędy serwera byłyby 
niediagnozowalne z samych logów.
Reguła „dokument musi być w stanie `Draft`” w obu handlerach wyodrębniona do `SalesDocument::ensureIsDraft()` (DRY).

## Problem 3 - zamiana kontrahenta z twórcą w raportach

### Diagnoza
Błąd nie był widoczny w żadnym z istniejących testów i nie występował "za każdym razem", co sugerowało problem 
w ścieżce wykonywania kodu, przez którą przechodzą tylko *niektóre* dokumenty. 

Analiza metody `SalesDocumentController::create()` wykazała błąd mapowania danych przychodzących w `$payload`.

Problem dotyczył wyłącznie dokumentów tworzonych za pośrednictwem endpointu HTTP - `ApproveSalesDocumentTest` pomijał 
kontroler wysyłając komendę `CreateSalesDocument` bezpośrednio na szynę, więc testy nie wykryły błędnego mapowania.

### Rozwiązanie
Poprawne przypisanie danych pod klucze w `SalesDocumentController::resolveDocumentOwnership()`.

## Problem 4 - `RejectSalesDocument` / `RejectSalesDocumentHandler`

Implementacja bazuje na dostarczonym teście `tests/Functional/RejectSalesDocumentHandlerTest.php`:
- nowy element `SalesDocumentStatus::Rejected`,
- nowe pola w `SalesDocument`: `rejectedBy` i `rejectedAt` (oba `nullable`), analogiczne do `approvedBy` i `approvedAt`,
- klasa `RejectSalesDocumentHandler` wymaga, aby dokument znajdował się w stanie `Draft` (szkic); 
  próba odrzucenia dokumentu w innym stanie rzuca wyjątkiem `InvalidSalesDocumentStateException`.


## Wersje Symfony i zależności

Projekt opiera się o wersję Symfony 7.4. To LTS. Działa na aktywnie wspieranym PHP 8.4.
Jeśli ta wersja działa poprawnie, ma wszystkie niezbędne funkcjonalności, nie ma znanych luk bezpieczeństwa,
a aplikacja jest kluczowym zasobem, nie zmieniałabym wersji Symfony przed publikacją kolejnego LTS. 


## Podsumowując: u mnie działa ;-)
```bash
$ composer test
Created database "app_test" for connection named default
[notice] Migrating up to DoctrineMigrations\Version20260923000000
[notice] finished in 120.1ms, used 14M memory, 2 migrations executed, 3 sql queries

                                                                                                                        
 [OK] Successfully migrated to version: DoctrineMigrations\Version20260923000000                                        
                                                                                                                        


PHPUnit 13.3.0 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /mnt/c/Users/Marta/workspace/REKRU/ts-task/phpunit.dist.xml

.......                                                             7 / 7 (100%)

Time: 00:05.015, Memory: 32.00 MB

OK (7 tests, 19 assertions)
```
