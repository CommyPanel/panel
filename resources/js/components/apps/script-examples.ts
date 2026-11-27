/**
 * An example command for the deployment script of each stack the page exists for. It takes the stack as the
 * page has it: a raw key (`node`, from the setup hint) or the label an app carries (`Node.js`, `.NET`).
 */
export function scriptExample(stack: string | null): string {
    switch (stack?.toLowerCase()) {
        case 'laravel':
            return 'php artisan migrate --force';
        case 'node':
        case 'node.js':
            return 'npx prisma migrate deploy';
        case 'python':
            return 'python manage.py migrate';
        case 'dotnet':
        case '.net':
            return 'dotnet MyApp.dll --migrate';
        default:
            return './migrate.sh';
    }
}
