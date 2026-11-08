import { PageHeader } from '@/components/page-header';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';

type Props = {
    groups: {
        group: string;
        permissions: {
            name: string;
            label: string;
            description: string;
            roles: string[];
        }[];
    }[];
};

export default function Index({ groups }: Props) {
    const { t } = useT();

    return (
        <AppLayout title={t('Permissions')}>
            <PageHeader
                title={t('Permissions')}
                description={t(
                    'Everything a role can allow. The list comes with CommyPanel; to change who may do what, edit roles.',
                )}
            />
            {groups.map(({ group, permissions }) => (
                <Card key={group} title={group} flush>
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('Permission')}</th>
                                    <th>{t('What it allows')}</th>
                                    <th>{t('Roles')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {permissions.map((permission) => (
                                    <tr key={permission.name}>
                                        <td className="min-w-44 align-top">
                                            <div className="font-medium">
                                                {permission.label}
                                            </div>
                                            <code className="text-xs text-base-content/60">
                                                {permission.name}
                                            </code>
                                        </td>
                                        <td className="min-w-64 align-top text-sm text-base-content/80">
                                            {permission.description}
                                        </td>
                                        <td className="align-top">
                                            <div className="flex flex-wrap gap-1">
                                                {permission.roles.map(
                                                    (role) => (
                                                        <span
                                                            key={role}
                                                            className="badge-soft badge-neutral badge badge-sm"
                                                        >
                                                            {role}
                                                        </span>
                                                    ),
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            ))}
        </AppLayout>
    );
}
