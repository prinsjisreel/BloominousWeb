/**
 * BLOOMINOUS DASHBOARD SCRIPT
 */

document.addEventListener('DOMContentLoaded', () => {
    const sideMenu = document.querySelector("aside");
    const menuBtn = document.querySelector("#menu-btn");
    const closeBtn = document.querySelector("#close-btn");
    const themeToggler = document.querySelector(".theme-toggler");
    
    // --- 1. SIDEBAR CONTROLS (MOBILE) ---
    if (menuBtn && sideMenu) {
        menuBtn.addEventListener('click', () => {
            sideMenu.style.display = 'block';
        });
    }

    if (closeBtn && sideMenu) {
        closeBtn.addEventListener('click', () => {
            sideMenu.style.display = 'none';
        });
    }

    // --- 2. DARK THEME LOGIC ---
    const applyTheme = (theme) => {
        const sunIcon = themeToggler?.querySelector('i.fa-sun');
        const moonIcon = themeToggler?.querySelector('i.fa-moon');

        if (theme === 'dark') {
            document.body.classList.add('dark-theme-variables');
            if (sunIcon && moonIcon) {
                sunIcon.classList.remove('active');
                moonIcon.classList.add('active');
            }
        } else {
            document.body.classList.remove('dark-theme-variables');
            if (sunIcon && moonIcon) {
                sunIcon.classList.add('active');
                moonIcon.classList.remove('active');
            }
        }
    };

    // Initialize Theme mula sa LocalStorage
    const savedTheme = localStorage.getItem('theme') || 'light';
    applyTheme(savedTheme);

    if (themeToggler) {
        themeToggler.addEventListener('click', () => {
            const isDark = document.body.classList.toggle('dark-theme-variables');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            
            // Switch active class sa icons (Sun/Moon)
            themeToggler.querySelectorAll('i').forEach(icon => icon.classList.toggle('active'));
        });
    }

    // --- 3. DROPDOWN & ACTIVE LINK LOGIC ---
    const dropdownBtns = document.querySelectorAll('.dropdown-btn');
    const menuLinks = document.querySelectorAll(".sidebar a");
    const currentLocation = window.location.pathname.split("/").pop() || 'admin.php';

    // Toggle Dropdowns
    dropdownBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const parent = btn.parentElement;

            // Isara ang ibang dropdowns para hindi magulo
            document.querySelectorAll('.nav-dropdown').forEach(item => {
                if (item !== parent) {
                    item.classList.remove('active');
                }
            });

            parent.classList.toggle('active');
        });
    });

    // Auto-Highlight Active Link & Auto-Open Parent Dropdown
    menuLinks.forEach(link => {
        const linkHref = link.getAttribute("href");
        
        if (linkHref === currentLocation) {
            // Siguraduhing malinis bago lagyan ng active class
            menuLinks.forEach(l => l.classList.remove('active'));
            link.classList.add("active");
            
            // Kung ang link ay nasa loob ng dropdown (sub-menu), buksan ang main dropdown
            const parentDropdown = link.closest('.nav-dropdown');
            if (parentDropdown) {
                parentDropdown.classList.add('active');
            }
        }
    });

    // --- 4. LOGOUT CONFIRMATION ---
    const logoutBtn = document.querySelector('a[href="logout.php"]');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
            if (!confirm("Sigurado ka bang gusto mong mag-logout sa Bloominous?")) {
                e.preventDefault();
            }
        });
    }
});