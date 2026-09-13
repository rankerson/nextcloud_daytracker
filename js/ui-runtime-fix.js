(function () {
    'use strict';

    let savedAdminScrollTop = 0;
    let restoreScheduled = false;

    function adminContent() {
        return document.getElementById('dt-admin-content');
    }

    function adminModal() {
        return document.getElementById('dt-admin-modal');
    }

    function rememberAdminScroll() {
        const content = adminContent();
        if (content) {
            savedAdminScrollTop = content.scrollTop;
        }
    }

    function restoreAdminScroll() {
        if (restoreScheduled) {
            return;
        }

        restoreScheduled = true;
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                const content = adminContent();
                const modal = adminModal();

                if (content && modal && !modal.hidden) {
                    const maximum = Math.max(0, content.scrollHeight - content.clientHeight);
                    content.scrollTop = Math.min(savedAdminScrollTop, maximum);
                }

                restoreScheduled = false;
            });
        });
    }

    function isAdminMutationControl(target) {
        if (!(target instanceof Element)) {
            return false;
        }

        return Boolean(target.closest(
            '#dt-admin-content button, ' +
            '#dt-admin-add-timeslice, ' +
            '#dt-admin-add-category'
        ));
    }

    function initialize() {
        const content = adminContent();
        if (!content) {
            console.error('Daytracker UI-Fix: dt-admin-content wurde nicht gefunden.');
            return;
        }

        document.addEventListener('pointerdown', function (event) {
            if (isAdminMutationControl(event.target)) {
                rememberAdminScroll();
            }
        }, true);

        document.addEventListener('click', function (event) {
            if (isAdminMutationControl(event.target)) {
                restoreAdminScroll();
            }
        }, true);

        const observer = new MutationObserver(function (mutations) {
            const hasRelevantMutation = mutations.some(function (mutation) {
                return mutation.type === 'childList';
            });

            if (hasRelevantMutation) {
                restoreAdminScroll();
            }
        });

        observer.observe(content, {
            childList: true,
            subtree: true
        });

        content.addEventListener('scroll', function () {
            savedAdminScrollTop = content.scrollTop;
        }, { passive: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
