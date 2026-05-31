// Основной скрипт для слайдера и навигации

document.addEventListener('DOMContentLoaded', function() {
    // Слайдер
    const slider = document.querySelector('.slider');
    const slides = document.querySelectorAll('.slide');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const indicators = document.querySelectorAll('.indicator');
    let currentSlide = 0;
    const totalSlides = slides.length;

    // Функция для обновления слайдера
    function updateSlider() {
        slider.style.transform = `translateX(-${currentSlide * 100}%)`;
        
        // Обновление индикаторов
        indicators.forEach((indicator, index) => {
            if (index === currentSlide) {
                indicator.classList.add('active');
            } else {
                indicator.classList.remove('active');
            }
        });
    }

    // Следующий слайд
    nextBtn.addEventListener('click', function() {
        currentSlide = (currentSlide + 1) % totalSlides;
        updateSlider();
    });

    // Предыдущий слайд
    prevBtn.addEventListener('click', function() {
        currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
        updateSlider();
    });

    // Клик по индикаторам
    indicators.forEach(indicator => {
        indicator.addEventListener('click', function() {
            currentSlide = parseInt(this.getAttribute('data-slide'));
            updateSlider();
        });
    });

    // Автоматическое перелистывание слайдов
    let slideInterval = setInterval(() => {
        currentSlide = (currentSlide + 1) % totalSlides;
        updateSlider();
    }, 5000);

    // Остановка авто-перелистывания при наведении
    slider.addEventListener('mouseenter', () => {
        clearInterval(slideInterval);
    });

    slider.addEventListener('mouseleave', () => {
        slideInterval = setInterval(() => {
            currentSlide = (currentSlide + 1) % totalSlides;
            updateSlider();
        }, 5000);
    });

    // Плавная прокрутка для якорных ссылок
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            // Пропускаем ссылки на саму себя
            if (href === '#') return;
            
            e.preventDefault();
            
            const targetElement = document.querySelector(href);
            if (targetElement) {
                const headerHeight = document.querySelector('.navbar').offsetHeight;
                const targetPosition = targetElement.offsetTop - headerHeight - 20;
                
                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });
                
                // Закрываем мобильное меню после клика
                const mobileMenu = document.getElementById('mobileMenu');
                if (mobileMenu.classList.contains('active')) {
                    mobileMenu.classList.remove('active');
                }
            }
        });
    });

    // Кнопки "Заказать" в слайдере
    document.querySelectorAll('.btn-order').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetElement = document.querySelector('#form');
            if (targetElement) {
                const headerHeight = document.querySelector('.navbar').offsetHeight;
                const targetPosition = targetElement.offsetTop - headerHeight - 20;
                
                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });
            }
        });
    });

    // Изменение фона навигации при прокрутке
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar');
        if (window.scrollY > 100) {
            navbar.style.backgroundColor = 'rgba(13, 27, 42, 0.95)';
            navbar.style.boxShadow = '0 5px 20px rgba(0, 0, 0, 0.1)';
        } else {
            navbar.style.backgroundColor = 'rgba(13, 27, 42, 0.9)';
            navbar.style.boxShadow = 'none';
        }
    });

    // Инициализация
    updateSlider();
});
// Слайдер (оставляем как есть)
document.addEventListener('DOMContentLoaded', function() {
    const slider = document.querySelector('.slider');
    const slides = document.querySelectorAll('.slide');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const indicators = document.querySelectorAll('.indicator');
    let currentSlide = 0;
    const totalSlides = slides.length;

    function updateSlider() {
        slider.style.transform = `translateX(-${currentSlide * 100}%)`;
        indicators.forEach((indicator, index) => {
            if (index === currentSlide) indicator.classList.add('active');
            else indicator.classList.remove('active');
        });
    }

    if (nextBtn && prevBtn) {
        nextBtn.addEventListener('click', () => { currentSlide = (currentSlide + 1) % totalSlides; updateSlider(); });
        prevBtn.addEventListener('click', () => { currentSlide = (currentSlide - 1 + totalSlides) % totalSlides; updateSlider(); });
    }

    indicators.forEach(indicator => {
        indicator.addEventListener('click', function() { currentSlide = parseInt(this.getAttribute('data-slide')); updateSlider(); });
    });

    let slideInterval = setInterval(() => { currentSlide = (currentSlide + 1) % totalSlides; updateSlider(); }, 5000);
    
    if (slider) {
        slider.addEventListener('mouseenter', () => clearInterval(slideInterval));
        slider.addEventListener('mouseleave', () => { slideInterval = setInterval(() => { currentSlide = (currentSlide + 1) % totalSlides; updateSlider(); }, 5000); });
    }

    // Плавная прокрутка
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href === '#') return;
            e.preventDefault();
            const targetElement = document.querySelector(href);
            if (targetElement) {
                const headerHeight = document.querySelector('.navbar')?.offsetHeight || 0;
                window.scrollTo({ top: targetElement.offsetTop - headerHeight - 20, behavior: 'smooth' });
            }
            const mobileMenu = document.getElementById('mobileMenu');
            if (mobileMenu?.classList.contains('active')) mobileMenu.classList.remove('active');
        });
    });

    // Мобильное меню
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    const closeMenuBtn = document.getElementById('closeMenuBtn');
    
    if (mobileMenuBtn && mobileMenu) {
        mobileMenuBtn.addEventListener('click', () => { mobileMenu.classList.add('active'); document.body.style.overflow = 'hidden'; });
        if (closeMenuBtn) closeMenuBtn.addEventListener('click', () => { mobileMenu.classList.remove('active'); document.body.style.overflow = ''; });
        document.addEventListener('click', function(e) {
            if (mobileMenu.classList.contains('active') && !mobileMenu.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                mobileMenu.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }

    // Изменение фона навигации
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar');
        if (navbar) {
            if (window.scrollY > 100) navbar.style.backgroundColor = 'rgba(13, 27, 42, 0.95)';
            else navbar.style.backgroundColor = 'rgba(13, 27, 42, 0.9)';
        }
    });
});
