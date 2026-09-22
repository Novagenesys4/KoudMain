-- Exécuté UNE seule fois, à la première création du volume Docker.
-- Base réservée aux tests automatiques (php artisan test) : elle est vidée à chaque exécution.
-- Si votre volume existe déjà, créez-la à la main :
--   docker compose exec db psql -U koudmain -d koudmain_laravel -c "CREATE DATABASE koudmain_test"
CREATE DATABASE koudmain_test OWNER koudmain;
