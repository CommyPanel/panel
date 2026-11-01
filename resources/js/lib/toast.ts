import { toast } from 'react-toastify';

/**
 * Short messages that confirm something happened. The text is also the toast's
 * id, so the same message shown twice in a row does not stack up.
 */
export const notify = {
    success: (message: string) => toast.success(message, { toastId: message }),
    info: (message: string) => toast.info(message, { toastId: message }),
    // Warnings and errors stay a little longer: they usually need reading.
    warning: (message: string) =>
        toast.warning(message, { toastId: message, autoClose: 10000 }),
    error: (message: string) =>
        toast.error(message, { toastId: message, autoClose: 10000 }),
};
