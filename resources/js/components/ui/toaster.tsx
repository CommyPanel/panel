import { Slide, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

/**
 * Where toast notifications appear. Colours come from the theme tokens (see
 * `.Toastify` in app.css), so they follow the light, dim and dark themes.
 * Mounted once, in the app layout.
 */
export function Toaster() {
    return (
        <ToastContainer
            position="bottom-right"
            autoClose={6000}
            newestOnTop
            closeOnClick
            pauseOnHover
            pauseOnFocusLoss
            limit={4}
            theme="light"
            transition={Slide}
        />
    );
}
