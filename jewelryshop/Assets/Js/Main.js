// assets/js/main.js
$(document).ready(function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Add to cart functionality
    $('.add-to-cart-btn').click(function(e) {
        e.preventDefault();
        var productId = $(this).data('product-id');
        var quantity = $('#quantity').val() || 1;
        
        $.ajax({
            url: '../user/add-to-cart.php',
            method: 'POST',
            data: {
                product_id: productId,
                quantity: quantity
            },
            success: function(response) {
                if(response.success) {
                    showNotification('Product added to cart!', 'success');
                    updateCartCount();
                } else {
                    showNotification(response.message || 'Error adding to cart', 'error');
                }
            },
            error: function() {
                showNotification('Error adding to cart', 'error');
            }
        });
    });
    
    // Update cart quantity
    $('.update-cart').on('click', '.update-quantity', function() {
        var cartId = $(this).data('cart-id');
        var quantity = $(this).closest('tr').find('.quantity-input').val();
        
        $.ajax({
            url: '../user/update-cart.php',
            method: 'POST',
            data: {
                cart_id: cartId,
                quantity: quantity
            },
            success: function(response) {
                if(response.success) {
                    location.reload();
                } else {
                    showNotification('Error updating cart', 'error');
                }
            }
        });
    });
    
    // Remove from cart
    $('.remove-from-cart').click(function(e) {
        e.preventDefault();
        var cartId = $(this).data('cart-id');
        
        if(confirm('Are you sure you want to remove this item?')) {
            $.ajax({
                url: '../user/remove-from-cart.php',
                method: 'POST',
                data: {
                    cart_id: cartId
                },
                success: function(response) {
                    if(response.success) {
                        location.reload();
                    } else {
                        showNotification('Error removing item', 'error');
                    }
                }
            });
        }
    });
    
    // Show notification
    function showNotification(message, type) {
        var bgClass = type === 'success' ? 'bg-success' : 'bg-danger';
        var icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        
        var notification = `
            <div class="toast-notification">
                <div class="card ${bgClass} text-white">
                    <div class="card-body">
                        <i class="fas ${icon} me-2"></i>
                        ${message}
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(notification);
        setTimeout(function() {
            $('.toast-notification').fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    // Update cart count
    function updateCartCount() {
        $.ajax({
            url: '../user/get-cart-count.php',
            method: 'GET',
            success: function(response) {
                $('.cart-count').text(response.count);
            }
        });
    }
    
    // Product image gallery
    $('.thumbnail').click(function() {
        var newSrc = $(this).attr('src');
        $('.main-image').attr('src', newSrc);
        $('.thumbnail').removeClass('border-primary');
        $(this).addClass('border-primary');
    });
    
    // Quantity increment/decrement
    $('.quantity-plus').click(function() {
        var input = $(this).siblings('.quantity-input');
        var value = parseInt(input.val());
        input.val(value + 1);
    });
    
    $('.quantity-minus').click(function() {
        var input = $(this).siblings('.quantity-input');
        var value = parseInt(input.val());
        if(value > 1) {
            input.val(value - 1);
        }
    });
    
    // Form validation
    $('form').on('submit', function(e) {
        var required = $(this).find('[required]');
        var isValid = true;
        
        required.each(function() {
            if(!$(this).val()) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if(!isValid) {
            e.preventDefault();
            showNotification('Please fill in all required fields', 'error');
        }
    });
    
    // Search functionality
    $('#search-input').on('keyup', function() {
        var searchTerm = $(this).val();
        if(searchTerm.length > 2) {
            $.ajax({
                url: '../user/search-products.php',
                method: 'POST',
                data: {search: searchTerm},
                success: function(response) {
                    $('#product-list').html(response);
                }
            });
        }
    });
    
    // Filter products
    $('.filter-checkbox').change(function() {
        var filters = {};
        $('.filter-checkbox:checked').each(function() {
            var filterType = $(this).data('filter');
            if(!filters[filterType]) {
                filters[filterType] = [];
            }
            filters[filterType].push($(this).val());
        });
        
        $.ajax({
            url: '../user/filter-products.php',
            method: 'POST',
            data: {filters: filters},
            success: function(response) {
                $('#product-list').html(response);
            }
        });
    });
    
    // Loading spinner
    function showSpinner() {
        if($('.spinner-overlay').length === 0) {
            $('body').append('<div class="spinner-overlay"><div class="spinner"></div></div>');
        }
        $('.spinner-overlay').show();
    }
    
    function hideSpinner() {
        $('.spinner-overlay').hide();
    }
    
    // Ajax setup for loading indicator
    $(document).ajaxStart(function() {
        showSpinner();
    });
    
    $(document).ajaxStop(function() {
        hideSpinner();
    });
});