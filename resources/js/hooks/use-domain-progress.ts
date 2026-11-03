import DomainController from '@/actions/App/Http/Controllers/DomainController';
import { useProgress } from '@/hooks/use-progress';
import { useCan } from '@/lib/permissions';
import { postQuietly } from '@/lib/post-json';
import type { DomainRow } from '@/types';

/**
 * Follows domains that are waiting for something: DNS that does not point at
 * the server yet (it is re-checked every 15 seconds by anyone allowed to check
 * domains) or a certificate that is being issued (only looked at).
 */
export function useDomainProgress(domains: DomainRow[], only: string[]) {
    const canCheck = useCan()('domains.manage');

    const waitingForDns = domains.filter(
        (domain) =>
            domain.status === 'pending' || domain.status === 'misconfigured',
    );
    const waitingForCertificate = domains.some(
        (domain) => domain.status === 'verified' && domain.tls === 'pending',
    );

    return useProgress({
        active: waitingForDns.length > 0 || waitingForCertificate,
        only,
        checks:
            canCheck && waitingForDns.length > 0
                ? () =>
                      Promise.all(
                          waitingForDns
                              .slice(0, 5)
                              .map((domain) =>
                                  postQuietly(
                                      DomainController.check(domain.id).url,
                                  ),
                              ),
                      )
                : undefined,
    });
}
