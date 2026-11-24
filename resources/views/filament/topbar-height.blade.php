{{--
    Панель считает шапку высотой 4rem: боковое меню прилипает на 4rem от верха, а меню и область содержимого получают
    высоту «окно минус 4rem». С высоким логотипом шапка выше, из-за чего каждая страница прокручивается на разницу,
    а меню уезжает под шапку. Здесь настоящая высота шапки измеряется и подставляется вместо 4rem.
--}}
<style>
    .fi-body-has-topbar .fi-main-ctn {
        min-height: calc(100dvh - var(--panel-topbar-h, 4rem)) !important;
    }

    /* Только для широких экранов: на узких меню — выезжающая панель на всю высоту, её не трогаем. */
    @media (min-width: 64rem) {
        .fi-body-has-topbar .fi-sidebar {
            top: var(--panel-topbar-h, 4rem) !important;
            height: calc(100dvh - var(--panel-topbar-h, 4rem)) !important;
        }
    }
</style>

<script>
    (() => {
        const measure = () => {
            const topbar = document.querySelector('.fi-topbar-ctn');

            if (topbar) {
                document.documentElement.style.setProperty(
                    '--panel-topbar-h',
                    Math.ceil(topbar.getBoundingClientRect().height) + 'px',
                );
            }

            return topbar;
        };

        const start = () => {
            const topbar = measure();

            // Высота шапки меняется после загрузки логотипа и при изменении ширины окна.
            if (topbar && 'ResizeObserver' in window) {
                new ResizeObserver(measure).observe(topbar);
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    })();
</script>
